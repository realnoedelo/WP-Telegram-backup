<?php
namespace ParcelBackups;
if (!defined('ABSPATH')) { exit; }

final class Storage {
    private static ?string $root = null;
    private static function identity(): string {
        global $wpdb;
        return hash('sha256', ABSPATH . '|' . (defined('DB_NAME') ? DB_NAME : '') . '|' . ($wpdb->prefix ?? ''));
    }
    public static function root(): string {
        if (self::$root !== null) { return self::$root; }
        $dir = defined('TELEGRAM_BACKUP_STORAGE_DIR') ? (string)TELEGRAM_BACKUP_STORAGE_DIR : (defined('PARCEL_STORAGE_DIR') ? (string)PARCEL_STORAGE_DIR : rtrim(get_temp_dir(),'/\\') . '/parcel-backups-' . substr(self::identity(),0,20));
        if ($dir==='' || !path_is_absolute($dir) || is_link($dir)) { throw new \RuntimeException('Storage must be an absolute path to a dedicated directory, not a symbolic link.'); }
        if (!is_dir($dir) && !wp_mkdir_p($dir)) { throw new \RuntimeException('Could not create private storage. Set TELEGRAM_BACKUP_STORAGE_DIR outside the public site directory.'); }
        $real=realpath($dir);
        if ($real===false) { throw new \RuntimeException('Could not resolve the storage path.'); }
        foreach ([ABSPATH, $_SERVER['DOCUMENT_ROOT'] ?? ''] as $public) {
            if ($public==='' || !is_dir($public)) { continue; }
            $base=realpath($public);
            if ($base && ($real===$base || strpos($real,$base.DIRECTORY_SEPARATOR)===0)) {
                throw new \RuntimeException('Storage must be outside the public site directory. Set TELEGRAM_BACKUP_STORAGE_DIR outside the document root.');
            }
        }
        $owner=self::identity();
        $marker=$real.'/.parcel-owner';
        if (is_file($marker) && trim((string)file_get_contents($marker))!==$owner) { throw new \RuntimeException('This directory belongs to another site. Choose a separate storage directory.'); }
        if (!is_file($marker)) {
            $existing=array_diff(scandir($real) ?: [],['.','..']);
            if ($existing) { throw new \RuntimeException('New storage requires an empty, dedicated directory.'); }
            @chmod($real,0700);
            if (file_put_contents($marker,$owner,LOCK_EX)===false) { throw new \RuntimeException('Could not write the storage ownership marker.'); }
            @chmod($marker,0600);
        }
        @chmod($real,0700);
        if (!is_writable($real) || (DIRECTORY_SEPARATOR==='/' && (fileperms($real)&0077)!==0)) { throw new \RuntimeException('Storage requires permissions 0700 and write access for PHP.'); }
        self::$root=$real;
        return $real;
    }
    public static function valid_id(string $id): bool { return (bool)preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',$id); }
    public static function job_dir(string $id): string {
        if (!self::valid_id($id)) { throw new \InvalidArgumentException('Invalid job ID.'); }
        $path=self::root().'/'.$id;
        if (is_link($path)) { throw new \RuntimeException('Symbolic links are not allowed in storage.'); }
        if (!is_dir($path) && !mkdir($path,0700)) { throw new \RuntimeException('Could not create the job directory.'); }
        return $path;
    }
    public static function read_job(string $id): ?array {
        if (!self::valid_id($id)) { return null; }
        $path=self::root().'/'.$id.'/job.json';
        if (!is_file($path) || is_link(dirname($path)) || is_link($path)) { return null; }
        $data=json_decode((string)file_get_contents($path),true);
        if (!is_array($data) || ($data['id']??'')!==$id) { throw new \RuntimeException('Corrupted state for job '.$id.'. The files have been preserved.'); }
        return $data;
    }
    public static function write_job(array $job): void {
        $dir=self::job_dir((string)($job['id']??''));
        $job['updated_at']=Support::now();
        $json=wp_json_encode($job,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if ($json===false) { throw new \RuntimeException('Could not write the job state.'); }
        $temp=$dir.'/job.json.tmp.'.bin2hex(random_bytes(4));
        if (file_put_contents($temp,$json,LOCK_EX)!==strlen($json)) { @unlink($temp); throw new \RuntimeException('Could not save the job state: check available disk space.'); }
        @chmod($temp,0600);
        if (!rename($temp,$dir.'/job.json')) { @unlink($temp); throw new \RuntimeException('Could not update the job state.'); }
    }
    public static function jobs(): array {
        $jobs=[];
        foreach (glob(self::root().'/*/job.json') ?: [] as $path) {
            $id=basename(dirname($path));
            if (!self::valid_id($id)) { continue; }
            $job=self::read_job($id);
            if ($job) { $jobs[]=$job; }
        }
        usort($jobs,static fn($a,$b)=>strcmp($b['created_at']??'', $a['created_at']??''));
        return $jobs;
    }
    public static function size(string $dir): int {
        if (!is_dir($dir) || is_link($dir)) { return 0; }
        $size=0;
        $it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir,\FilesystemIterator::SKIP_DOTS));
        foreach ($it as $item) { if ($item->isFile() && !$item->isLink()) { $size+=$item->getSize(); } }
        return $size;
    }
    public static function usage(): array {
        $root=self::root(); $s=Settings::get(); $free=disk_free_space($root);
        return ['used_bytes'=>self::size($root),'free_bytes'=>$free===false?null:(int)$free,
            'quota_bytes'=>$s['quota_mb']*1048576,'reserve_bytes'=>$s['reserve_mb']*1048576,
            'path'=>$root,'temporary'=>!defined('TELEGRAM_BACKUP_STORAGE_DIR') && !defined('PARCEL_STORAGE_DIR')];
    }
    public static function ensure_space(int $additional, string $jobId=''): void {
        $additional=max(0,$additional); $usage=self::usage();
        if ($usage['used_bytes']+$additional>$usage['quota_bytes']) { self::cleanup($additional); $usage=self::usage(); }
        if ($usage['used_bytes']+$additional>$usage['quota_bytes']) { throw new \RuntimeException('Insufficient backup storage quota. Delete unnecessary local backups or increase the total limit. Undelivered backups are never deleted automatically.'); }
        if ($usage['free_bytes']===null || $usage['free_bytes']-$additional<$usage['reserve_bytes']) { throw new \RuntimeException('Insufficient free space after accounting for the required reserve. The backup was stopped before filling the disk.'); }
    }
    public static function cleanup(int $additional=0): array {
        $s=Settings::get(); $jobs=self::jobs();
        $completed=array_values(array_filter($jobs,static fn($j)=>($j['status']??'')==='completed' && empty($j['local_deleted'])));
        $delete=[];
        foreach ($completed as $n=>$job) {
            if ($n>=$s['retention_count'] || strtotime($job['created_at'])<time()-$s['retention_days']*DAY_IN_SECONDS) { $delete[$job['id']]=$job; }
        }
        $used=self::size(self::root()); $quota=$s['quota_mb']*1048576;
        foreach (array_reverse($completed) as $job) {
            $jobDir=self::job_dir($job['id']);
            $bytes=self::size($jobDir);
            foreach (['job.json','events.jsonl'] as $retained) { if (is_file($jobDir.'/'.$retained)) { $bytes-=filesize($jobDir.'/'.$retained); } }
            if (isset($delete[$job['id']])) { $used-=$bytes; }
            elseif ($used+$additional>$quota) { $delete[$job['id']]=$job; $used-=$bytes; }
        }
        $removed=[];
        foreach ($delete as $job) { self::purge_artifacts($job); $removed[]=$job['id']; }
        // Keep bounded history only after its delivered artifacts have already been removed.
        $history=0;
        foreach (self::jobs() as $job) {
            if (empty($job['local_deleted'])) { continue; }
            if (++$history>100) { self::remove_tree(self::root().'/'.$job['id']); }
        }
        return ['removed'=>$removed];
    }
    private static function purge_artifacts(array $job): void {
        $dir=self::job_dir($job['id']);
        foreach (scandir($dir) ?: [] as $name) {
            if (in_array($name,['.','..','job.json','events.jsonl'],true)) { continue; }
            self::remove_tree($dir.'/'.$name);
        }
        $job['local_deleted']=true; $job['local_deleted_at']=Support::now();
        self::write_job($job);
    }
    public static function delete_job(string $id): void {
        $job=self::read_job($id);
        if (!$job) { throw new \RuntimeException('Job not found.'); }
        if (in_array($job['status'],['queued','running','retry'],true)) { throw new \RuntimeException('Cancel the active job first.'); }
        self::remove_tree(self::root().'/'.$id);
    }
    public static function remove_tree(string $path): void {
        $root=self::root(); $parent=realpath(dirname($path));
        if (!$parent || ($parent!==$root && strpos($parent,$root.DIRECTORY_SEPARATOR)!==0) || $path===$root || in_array(basename($path),['.','..','.parcel-owner','worker.lock'],true)) { throw new \RuntimeException('Deleting files outside the backup storage directory is not allowed.'); }
        if (is_link($path) || is_file($path)) { if (!unlink($path)) { throw new \RuntimeException('Could not delete the file.'); } return; }
        if (!is_dir($path)) { return; }
        foreach (scandir($path) ?: [] as $name) { if ($name!=='.' && $name!=='..') { self::remove_tree($path.'/'.$name); } }
        if (!rmdir($path)) { throw new \RuntimeException('Could not delete the directory.'); }
    }
    public static function lock() {
        $fp=fopen(self::root().'/worker.lock','c+');
        if (!$fp) { throw new \RuntimeException('Could not open the queue lock.'); }
        @chmod(self::root().'/worker.lock',0600);
        if (!flock($fp,LOCK_EX|LOCK_NB)) { fclose($fp); return false; }
        return $fp;
    }
    public static function unlock($fp): void { if (is_resource($fp)) { flock($fp,LOCK_UN); fclose($fp); } }
}
