<?php
/** Isolated worker/status lifecycle tests. No WordPress database or Telegram connection is used. */
namespace ParcelBackups {
    if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
    final class Settings {
        public static function get(): array { return ['consent'=>true,'chat_id'=>'-100123456','thread_id'=>0,'api_mode'=>'cloud','api_url'=>'https://api.telegram.org','part_mb'=>2,'max_backup_mb'=>64,'excludes'=>'']; }
        public static function secret(string $key): string { return $key === 'password' ? 'isolated-test-password' : '123456789:isolated-test-token'; }
    }
    final class Storage {
        public static string $directory;
        public static array $data = [];
        private static bool $locked = false;
        public static function lock() { if (self::$locked) { return false; } self::$locked = true; return true; }
        public static function unlock($lock): void { self::$locked = false; }
        public static function jobs(): array { return array_values(self::$data); }
        public static function job_dir(string $id): string { return self::$directory; }
        public static function root(): string { return self::$directory; }
        public static function write_job(array $job): void { self::$data[$job['id']] = $job; }
        public static function read_job(string $id): ?array { return self::$data[$id] ?? null; }
        public static function cleanup(): array { return []; }
    }
    final class Archive { public static function recover(string $dir, array &$state): void {} }
    final class Support {
        public static function now(): string { return gmdate('c'); }
        public static function redact(string $text): string { return $text; }
        public static function log(...$args): void {}
    }
    final class Telegram {
        public static array $calls = [];
        public static array $chat = ['type'=>'supergroup','id'=>-100123456];
        public static ?\Throwable $sendError = null;
        public static ?\Throwable $editError = null;
        public function send_message(string $text): array {
            $job = reset(Storage::$data);
            if (empty($job['status_send_attempted'])) { throw new \LogicException('Announcement attempt must be saved before Telegram I/O.'); }
            self::$calls[] = ['method'=>'sendMessage','text'=>$text];
            if (self::$sendError) { throw self::$sendError; }
            return ['message_id'=>100,'chat'=>self::$chat];
        }
        public function edit_message(int $id, string $text): array {
            self::$calls[] = ['method'=>'editMessageText','text'=>$text,'message_id'=>$id];
            if (self::$editError) { throw self::$editError; }
            return ['message_id'=>$id,'chat'=>self::$chat];
        }
        public function send_document(string $path, string $caption = ''): array {
            self::$calls[] = ['method'=>'sendDocument','file'=>basename($path)];
            return ['message_id'=>200 + count(self::$calls),'chat'=>self::$chat];
        }
    }
}
namespace {
    define('ABSPATH', dirname(__DIR__) . '/');
    require dirname(__DIR__) . '/includes/TelegramException.php';
    require dirname(__DIR__) . '/includes/Engine.php';
    use ParcelBackups\Engine;
    use ParcelBackups\Storage;
    use ParcelBackups\Telegram;
    use ParcelBackups\TelegramException;
    $options = []; $checks = 0;
    function get_option($key,$default=false) { global $options; return $options[$key] ?? $default; }
    function update_option($key,$value,$autoload=false) { global $options; $options[$key]=$value; return true; }
    function wp_json_encode($value): string { return json_encode($value, JSON_THROW_ON_ERROR); }
    function is_multisite(): bool { return false; }
    function check(bool $ok,string $message): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: '.$message); } $checks++; echo "PASS $message\n"; }
    function method_calls(string $method): array { return array_values(array_filter(Telegram::$calls,static fn($call)=>$call['method']===$method)); }
    function job(): array { return reset(Storage::$data); }
    function advance_clock(): void { $job=job(); $job['status_last_attempt']=time()-31; $job['status_retry_at']=0; Storage::write_job($job); }
    function fixture(): void {
        Telegram::$calls=[]; Telegram::$sendError=null; Telegram::$editError=null;
        Telegram::$chat=['type'=>'supergroup','id'=>-100123456]; Storage::$data=[];
        $parts=[];
        for($i=1;$i<=2;$i++) {
            $name=sprintf('part-%05d.pvlt',$i); $bytes=file_put_contents(Storage::$directory.'/'.$name,'encrypted-fixture-'.$i);
            $parts[]=['name'=>$name,'bytes'=>$bytes,'sha256'=>hash_file('sha256',Storage::$directory.'/'.$name)];
        }
        file_put_contents(Storage::$directory.'/manifest.json','{}'); file_put_contents(Storage::$directory.'/restore.php','fixture');
        $fingerprint=(new ReflectionMethod(Engine::class,'fingerprint'))->invoke(null);
        Storage::write_job(['id'=>'12345678-1234-1234-1234-123456789abc','status'=>'queued','stage'=>'send','type'=>'database',
            'created_at'=>gmdate('c'),'progress'=>70,'bytes'=>36,'files'=>1,'parts'=>$parts,'archive_state'=>[],
            'scan_state'=>['index_bytes'=>0],'settings_fingerprint'=>$fingerprint,'attempts'=>0,'error'=>'','retry_at'=>0]);
    }
    function sync(array &$job,bool $force=false): void { (new ReflectionMethod(Engine::class,'sync_status'))->invokeArgs(null,[&$job,$force]); }
    Storage::$directory=sys_get_temp_dir().'/tg-backup-status-test-'.bin2hex(random_bytes(5)); mkdir(Storage::$directory,0700);
    try {
        fixture(); Engine::tick();
        check(count(method_calls('sendMessage'))===1 && !empty(job()['status_message_id']),'first worker tick creates one persisted announcement');
        check(count(method_calls('sendDocument'))===1 && count(method_calls('editMessageText'))===0,'immediate progress is throttled while first document is delivered');
        advance_clock(); Engine::tick();
        check(count(method_calls('editMessageText'))===1 && count(method_calls('sendMessage'))===1,'later tick edits progress without another announcement');
        for($i=0;$i<4;$i++) { Engine::tick(); }
        check(job()['status']==='completed' && count(method_calls('sendDocument'))===4,'all parts and helpers complete successfully');
        $edits=method_calls('editMessageText'); $final=end($edits);
        check(count(method_calls('sendMessage'))===1 && str_contains($final['text'],'TG Backup — Complete'),'completion edits the original announcement');
        check($final['message_id']===100 && str_contains($final['text'],'https://t.me/c/123456/'),'confirmed private supergroup links refer to delivered messages');
        $before=count(Telegram::$calls); Engine::tick(); $done=job(); sync($done,true);
        check(count(Telegram::$calls)===$before,'completed repeated ticks and identical payloads do not announce or edit again');

        fixture(); Telegram::$sendError=new TelegramException('reply timed out',true,60,28);
        for($i=0;$i<6;$i++) { Engine::tick(); }
        check(job()['status']==='completed' && count(method_calls('sendMessage'))===1,'ambiguous initial timeout never creates a duplicate and does not fail backup');
        check(job()['attempts']===0,'optional status failure does not increase backup retry counters');

        fixture(); Engine::tick(); advance_clock(); Telegram::$editError=new TelegramException('message to edit not found',false,0,400);
        for($i=0;$i<5;$i++) { Engine::tick(); }
        check(job()['status']==='completed' && !empty(job()['status_edit_disabled']),'deleted status message stops edits without failing delivered backup');
        check(count(method_calls('sendMessage'))===1 && count(method_calls('editMessageText'))===1,'deleted message is not repeatedly edited or replaced');

        fixture(); Engine::tick(); advance_clock(); Telegram::$editError=new TelegramException('temporary edit failure',true,60,503); Engine::tick();
        check(job()['status']==='running' && job()['attempts']===0 && job()['status_retry_at']>time(),'transient status edit uses independent backoff');
        $before=count(method_calls('editMessageText')); Engine::tick();
        check(count(method_calls('editMessageText'))===$before,'status edit backoff prevents repeated requests');
        Telegram::$editError=null; advance_clock(); Engine::tick(); Engine::tick();
        check(job()['status']==='completed' && count(method_calls('sendMessage'))===1,'status edits recover without resending announcement');

        fixture(); Engine::tick(); Telegram::$editError=new TelegramException('terminal edit timed out',true,60,503);
        for($i=0;$i<5;$i++) { Engine::tick(); }
        check(job()['status']==='completed' && job()['status_retry_at']>time(),'failed final status edit preserves completed backup and schedules a retry');
        $before=count(method_calls('editMessageText')); Engine::tick();
        check(count(method_calls('editMessageText'))===$before,'idle worker honors final status retry delay');
        Telegram::$editError=null; advance_clock(); Engine::tick(); $edits=method_calls('editMessageText');
        check(count($edits)===$before+1 && str_contains(end($edits)['text'],'TG Backup — Complete'),'idle worker retries the pending final edit');
        $before=count(Telegram::$calls); Engine::tick();
        check(count(Telegram::$calls)===$before && count(method_calls('sendMessage'))===1,'acknowledged final edit is not repeated or announced again');
        $limited=job(); $limited['status_hash']='stale'; $limited['status_failures']=6; $limited['status_retry_at']=0; Storage::write_job($limited); Engine::tick();
        check(count(Telegram::$calls)===$before,'terminal status retries stop after six failed attempts');

        fixture(); Engine::tick(); Engine::cancel(job()['id']); $edits=method_calls('editMessageText');
        check(job()['status']==='cancelled' && str_contains(end($edits)['text'],'Cancelled'),'cancellation updates the existing status');
        fixture(); Engine::tick(); unlink(Storage::$directory.'/part-00002.pvlt'); Engine::tick(); $edits=method_calls('editMessageText');
        check(job()['status']==='failed' && str_contains(end($edits)['text'],'Failed'),'archive failure updates the existing status');

        fixture(); Telegram::$chat=['type'=>'private','id'=>123456,'username'=>'someuser'];
        for($i=0;$i<6;$i++) { Engine::tick(); } $edits=method_calls('editMessageText'); $final=end($edits)['text'];
        check(!str_contains($final,'https://t.me/') && str_contains($final,'direct file links are unavailable'),'private chats use an honest document/search fallback');
        $huge=job(); $huge['status_chat']=['type'=>'channel','id'=>-100123456,'username'=>'backupchannel']; $huge['parts']=[];
        for($i=0;$i<120;$i++) { $huge['parts'][]=['name'=>sprintf('part-%05d.pvlt',$i+1),'sent'=>true,'message_id'=>300+$i]; }
        $huge['error']=str_repeat('<b>literal & text</b> 🙂',1000);
        $text=(new ReflectionMethod(Engine::class,'status_text'))->invoke(null,$huge);
        check(strlen($text)<=3800 && preg_match('//u',$text)===1 && substr_count($text,'https://t.me/')<=12,'large statuses have bounded links and valid UTF-8 below Telegram limit');
        check(str_contains($text,'<b>literal & text</b>') && !str_contains($text,'&lt;'),'plain-text status preserves text without HTML encoding or a parse mode');
        check(str_contains($text,'110 additional parts'),'bounded link list clearly discloses omitted parts');
        echo "TOTAL $checks checks passed. No Telegram requests or WordPress database changes.\n";
    } finally {
        foreach(glob(Storage::$directory.'/*') ?: [] as $file) { unlink($file); } rmdir(Storage::$directory);
    }
}
