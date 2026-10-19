<?php
namespace ParcelBackups;
if (!defined('ABSPATH')) { exit; }
final class Api {
    public static function init(): void { add_action('rest_api_init',[self::class,'routes']); }
    public static function allowed(\WP_REST_Request $r) {
        if (!current_user_can('manage_options') || is_multisite()) { return new \WP_Error('parcel_forbidden','Administrator permissions on a single-site installation are required.',['status'=>403]); }
        $nonce=$r->get_header('X-WP-Nonce') ?: $r->get_param('_wpnonce');
        if (!$nonce || !wp_verify_nonce($nonce,'wp_rest')) { return new \WP_Error('parcel_nonce','Your session has expired. Refresh the page.',['status'=>403]); }
        return true;
    }
    private static function route(string $path,string $methods,callable $fn): void {
        register_rest_route('parcel-backups/v1',$path,['methods'=>$methods,'permission_callback'=>[self::class,'allowed'],'callback'=>static function($r) use($fn) {
            try { return $fn($r); }
            catch (\Throwable $e) { return new \WP_Error('parcel_error',Support::redact($e->getMessage()),['status'=>$e instanceof \InvalidArgumentException?400:409]); }
        }]);
    }
    public static function routes(): void {
        self::route('/status','GET',static fn()=>self::status());
        self::route('/settings','POST',static function($r) {
            $lock=Storage::lock(); if (!$lock) { throw new \RuntimeException('The queue is processing a step. Try saving again after it finishes.'); }
            try {
                foreach (Storage::jobs() as $job) { if (in_array($job['status'],['queued','running','retry'],true)) { throw new \RuntimeException('Wait for active jobs to finish or cancel them first. Settings cannot be changed during a backup.'); } }
                return Settings::save((array)$r->get_json_params());
            } finally { Storage::unlock($lock); }
        });
        self::route('/diagnostics','POST',static function($r) {
            self::consent(); $result=(new Telegram())->diagnostics((bool)$r->get_param('send_test'));
            $result['checked_at']=Support::now(); update_option('parcel_connection',$result,false); return $result;
        });
        self::route('/discover','POST',static function() { self::consent(); return ['chats'=>(new Telegram())->discover_chats()]; });
        self::route('/preflight','POST',static fn($r)=>Engine::preflight(self::type($r)));
        self::route('/jobs','POST',static fn($r)=>self::public_job(Engine::enqueue(self::type($r),'manual')));
        $id='(?P<id>[a-f0-9-]{36})';
        self::route('/jobs/'.$id.'/cancel','POST',static fn($r)=>self::public_job(Engine::cancel($r['id'])));
        self::route('/jobs/'.$id.'/retry','POST',static fn($r)=>self::public_job(Engine::retry($r['id'])));
        self::route('/jobs/'.$id,'DELETE',static function($r) {
            $lock=Storage::lock(); if (!$lock) { throw new \RuntimeException('The queue is busy. Try again later.'); }
            try { Storage::delete_job($r['id']); return ['deleted'=>true]; } finally { Storage::unlock($lock); }
        });
        self::route('/worker','POST',static function() { Engine::tick('manual'); return self::status(); });
        self::route('/repair-schedule','POST',static function() { Scheduler::reschedule(); return Scheduler::status(); });
        self::route('/system-cron/install','POST',static function() { SystemCron::install(); Scheduler::reschedule(); return self::status(); });
        self::route('/system-cron/remove','POST',static function() { SystemCron::remove(); return self::status(); });
        self::route('/cleanup','POST',static function() {
            $lock=Storage::lock(); if (!$lock) { throw new \RuntimeException('The queue is busy.'); }
            try { return Storage::cleanup(); } finally { Storage::unlock($lock); }
        });
        self::route('/logs','GET',static fn($r)=>['entries'=>Support::logs((string)($r->get_param('job') ?: ''))]);
        self::route('/download','GET',static function($r) {
            $lock=Storage::lock(); if (!$lock) { throw new \RuntimeException('The worker is busy. Download the file after the current step finishes.'); }
            try {
            $id=(string)$r->get_param('job'); $name=(string)$r->get_param('file'); $job=Storage::read_job($id);
            if (!$job || !in_array($job['status'],['completed','failed','cancelled'],true) || !empty($job['local_deleted'])) { throw new \RuntimeException('Local files are unavailable or are still being written.'); }
            $allowed=['manifest.json','restore.php'];
            foreach (($job['parts']??[]) as $part) { $allowed[]=$part['name']; }
            if (!in_array($name,$allowed,true) || basename($name)!==$name) { throw new \InvalidArgumentException('File unavailable.'); }
            return self::download(Storage::job_dir($id).'/'.$name,$name);
            } finally { Storage::unlock($lock); }
        });
        self::route('/recovery','GET',static fn()=>self::download(PARCEL_DIR.'tools/restore.php','tg-backup-restore.php'));
    }
    private static function consent(): void { if (!Settings::get()['consent']) { throw new \RuntimeException('First allow uploads to your Telegram account in the connection settings.'); } }
    private static function type($r): string {
        $type=(string)($r->get_param('type') ?: Settings::get()['backup_type']);
        if (!in_array($type,['full','database','files'],true)) { throw new \InvalidArgumentException('Invalid backup type.'); } return $type;
    }
    public static function public_job(array $job): array {
        $keys=['id','status','stage','type','origin','created_at','updated_at','completed_at','progress','message','error','source_bytes','bytes','files','parts','attempts','retry_at','local_deleted','local_deleted_at','warnings','manifest'];
        $out=array_intersect_key($job,array_flip($keys));
        $out['parts']=array_map(static fn($p)=>array_intersect_key($p,array_flip(['name','bytes','sha256','message_id','sent'])), $job['parts']??[]);
        return $out;
    }
    public static function status(): array {
        $out=['version'=>PARCEL_VERSION,'settings'=>Settings::public_data(),'jobs'=>[],'worker'=>get_option('parcel_worker_state',[]),
            'storage'=>null,'schedule'=>Scheduler::status(),'system_cron'=>SystemCron::status(),'requirements'=>Support::requirements(),'connection'=>get_option('parcel_connection',null)];
        try {
            $state=Engine::status(); $out['jobs']=array_map([self::class,'public_job'],$state['jobs']);
            $out['worker']=$state['worker']; $out['storage']=$state['storage'];
        } catch (\Throwable $e) { $out['storage_error']=Support::redact($e->getMessage()); }
        return $out;
    }
    private static function download(string $path,string $name) {
        if (!is_file($path) || is_link($path)) { throw new \RuntimeException('The file is missing. A retention rule may have already removed it.'); }
        while (ob_get_level()) { ob_end_clean(); }
        nocache_headers(); header('Content-Type: application/octet-stream'); header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: attachment; filename="'.sanitize_file_name($name).'"'); header('Content-Length: '.filesize($path));
        $fp=fopen($path,'rb'); if (!$fp) { throw new \RuntimeException('Could not open the file.'); } // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Stream the authorized binary attachment without buffering the whole backup.
        fpassthru($fp); fclose($fp); exit; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Stream the authorized binary attachment without buffering the whole backup.
    }
}
