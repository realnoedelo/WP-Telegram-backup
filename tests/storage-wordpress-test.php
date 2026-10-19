<?php
/**
 * Disposable WordPress regression checks. Never run against production.
 * PARCEL_TEST=1 php tests/storage-wordpress-test.php --wp=/tmp/wordpress --scratch=/tmp
 * Runtime option filters isolate limits; no settings/cron values are persisted.
 */
if (PHP_SAPI !== 'cli' || getenv('PARCEL_TEST') !== '1') { http_response_code(404); exit("Set PARCEL_TEST=1 and use a disposable WordPress installation.\n"); }
$args = getopt('', ['wp:', 'scratch:', 'foreign-child:']);
$wpRoot = isset($args['wp']) ? realpath($args['wp']) : false;
$scratch = isset($args['scratch']) ? realpath($args['scratch']) : false;
if (!$wpRoot || !is_file($wpRoot . '/wp-load.php') || !$scratch || !is_dir($scratch)) { throw new RuntimeException('Provide --wp and --scratch existing directories.'); }
$foreignChild = $args['foreign-child'] ?? null;
if ($foreignChild !== null) {
    if (!str_starts_with($foreignChild, $scratch . '/storage-regression-') || !is_dir($foreignChild) || is_link($foreignChild)) { throw new RuntimeException('Invalid fixture child.'); }
    $root = $foreignChild;
} else {
    $root = $scratch . '/storage-regression-' . bin2hex(random_bytes(8));
    if (!mkdir($root, 0700)) { throw new RuntimeException('Cannot create isolated fixture root.'); }
}
define('PARCEL_STORAGE_DIR', $root);
if (!defined('DISABLE_WP_CRON')) { define('DISABLE_WP_CRON', true); }
require $wpRoot . '/wp-load.php';
if (!class_exists('ParcelBackups\Storage')) { throw new RuntimeException('Activate Telegram Backup on the disposable installation first.'); }
if ($foreignChild !== null) {
    try { \ParcelBackups\Storage::root(); echo "unexpected-success\n"; exit(2); }
    catch (Throwable $error) { echo 'rejected: ' . $error->getMessage() . "\n"; exit(0); }
}

use ParcelBackups\Storage;
use ParcelBackups\Settings;
use ParcelBackups\Scheduler;

$checks = 0;
function parcel_check(bool $ok, string $message): void {
    global $checks;
    if (!$ok) { throw new RuntimeException('FAIL: ' . $message); }
    $checks++;
}
function parcel_reject(callable $call, string $contains): Throwable {
    try { $call(); } catch (Throwable $error) {
        parcel_check(str_contains($error->getMessage(), $contains), 'failure: ' . $error->getMessage());
        return $error;
    }
    throw new RuntimeException('FAIL: operation was accepted, expected ' . $contains);
}
function parcel_fixture(string $status, int $age, int $bytes = 4096): array {
    $id = wp_generate_uuid4();
    $job = ['id'=>$id, 'status'=>$status, 'stage'=>'complete', 'type'=>'files', 'created_at'=>gmdate('Y-m-d\TH:i:s\Z', time() - $age), 'parts'=>[['name'=>'part-00001.pvlt', 'bytes'=>$bytes, 'sha256'=>'fixture']]];
    Storage::write_job($job);
    $dir = Storage::job_dir($id);
    $h = fopen($dir . '/part-00001.pvlt', 'xb');
    if (!$h || !ftruncate($h, $bytes)) { throw new RuntimeException('Cannot create sparse fixture file'); }
    fclose($h);
    file_put_contents($dir . '/manifest.json', '{}');
    file_put_contents($dir . '/events.jsonl', "{}\n");
    return $job;
}
function parcel_part_exists(array $job): bool { return is_file(PARCEL_STORAGE_DIR . '/' . $job['id'] . '/part-00001.pvlt'); }
function parcel_fixture_remove(string $path, string $base): void {
    if ($path !== $base && !str_starts_with($path, $base . '/')) { throw new RuntimeException('Refuse cleanup outside fixture'); }
    if (is_link($path) || is_file($path)) { unlink($path); return; }
    if (is_dir($path)) {
        foreach (array_diff(scandir($path), ['.', '..']) as $name) { parcel_fixture_remove($path . '/' . $name, $base); }
        rmdir($path);
    }
}

$originalSettings = get_option(Settings::OPTION, null);
$originalCron = get_option('cron', null);
$originalConnection = get_option('parcel_connection', null);
$settings = array_replace(Settings::defaults(), is_array($originalSettings) ? $originalSettings : [], [
    'api_mode'=>'cloud','api_url'=>'https://api.telegram.org','part_mb'=>40,
    'quota_mb'=>128,'max_backup_mb'=>64,'reserve_mb'=>64,'retention_count'=>2,'retention_days'=>14,
    'dns_servers'=>'','doh_url'=>'','proxy_url'=>'',
]);
$filter = static function () use (&$settings) { return $settings; };
add_filter('pre_option_' . Settings::OPTION, $filter);
$lock = false;
try {
    parcel_check(Storage::root() === realpath($root), 'all storage uses isolated fixture root');
    parcel_check((fileperms($root) & 0777) === 0700, 'storage directory is 0700');
    $recent = parcel_fixture('completed', 300);
    $second = parcel_fixture('completed', DAY_IN_SECONDS);
    $third = parcel_fixture('completed', 2 * DAY_IN_SECONDS);
    $old = parcel_fixture('completed', 30 * DAY_IN_SECONDS);
    $preserved = [];
    foreach (['queued','running','retry','failed','cancelled'] as $status) { $preserved[] = parcel_fixture($status, 40 * DAY_IN_SECONDS); }
    $result = Storage::cleanup();
    parcel_check(count($result['removed']) === 2, 'retention count removes exactly two delivered copies');
    parcel_check(parcel_part_exists($recent) && parcel_part_exists($second), 'newest two completed artifacts retained');
    parcel_check(!parcel_part_exists($third) && !parcel_part_exists($old), 'older completed artifacts removed');
    parcel_check(Storage::read_job($third['id'])['local_deleted'] === true, 'retention preserves marked job history');
    parcel_check(is_file($root . '/' . $third['id'] . '/events.jsonl'), 'retention preserves logs');
    foreach ($preserved as $job) { parcel_check(parcel_part_exists($job), $job['status'] . ' artifacts preserved by retention'); }
    // A second scheduler must not enqueue another backup after the first one has taken the queue lock.
    $settingsBeforeQueue = $settings;
    $settings['consent'] = true;
    $settings['chat_id'] = '123456789';
    $settings['_token'] = Settings::encrypt('123456789:FAKE_TEST_TOKEN_NOT_FOR_TELEGRAM');
    $settings['_password'] = Settings::encrypt('isolated-test-backup-password');
    $jobCount = count(Storage::jobs());
    $existingScheduled = \ParcelBackups\Engine::enqueue('database', 'schedule');
    parcel_check(in_array($existingScheduled['status'], ['queued','running','retry'], true), 'scheduled enqueue reuses an active job');
    parcel_check(count(Storage::jobs()) === $jobCount, 'overlapping scheduled runs do not create another job');
    $settings = $settingsBeforeQueue;
    $settings['retention_count'] = 100;
    $oldWithinCount = parcel_fixture('completed', 20 * DAY_IN_SECONDS);
    $result = Storage::cleanup();
    parcel_check($result['removed'] === [$oldWithinCount['id']], 'age independently expires completed copy within count allowance');

    $lock = Storage::lock();
    parcel_check(is_resource($lock), 'first flock acquired');
    parcel_check(Storage::lock() === false, 'second flock acquisition excluded');
    Storage::unlock($lock); $lock = false;
    $lock = Storage::lock();
    parcel_check(is_resource($lock), 'flock released and acquired again');
    Storage::unlock($lock); $lock = false;
    parcel_reject(fn()=>Storage::delete_job($preserved[0]['id']), 'Cancel');
    parcel_reject(fn()=>Storage::job_dir('../outside'), 'ID');
    parcel_reject(fn()=>Storage::remove_tree(dirname($root) . '/not-a-fixture'), 'outside');

    // Sparse files exercise the logical quota without occupying hundreds of MB physically.
    $activeLarge = parcel_fixture('running', 10, 129 * 1048576);
    parcel_reject(fn()=>Storage::ensure_space(1), 'quota');
    parcel_check(parcel_part_exists($activeLarge), 'hard quota failure preserves active archive');
    foreach ($preserved as $job) { parcel_check(parcel_part_exists($job), $job['status'] . ' remains after hard quota failure'); }
    unlink($root . '/' . $activeLarge['id'] . '/part-00001.pvlt');
    $h = fopen($root . '/' . $activeLarge['id'] . '/part-00001.pvlt', 'xb'); ftruncate($h, 80 * 1048576); fclose($h);
    $completedLarge = parcel_fixture('completed', 5, 50 * 1048576);
    Storage::ensure_space(2 * 1048576);
    parcel_check(!parcel_part_exists($completedLarge) && parcel_part_exists($activeLarge), 'quota pressure evicts delivered artifacts only');
    parcel_check(Storage::usage()['used_bytes'] + 2 * 1048576 <= 128 * 1048576, 'quota includes retained metadata after eviction');
    $settings['reserve_mb'] = (int) ceil(disk_free_space($root) / 1048576) + 64;
    parcel_reject(fn()=>Storage::ensure_space(1), 'reserve');
    $settings['reserve_mb'] = 64;

    $secret = 'roundtrip-only-ArchivePassword!';
    $encrypted = Settings::encrypt($secret);
    parcel_check($encrypted !== $secret && !str_contains($encrypted, $secret), 'secret ciphertext is not plaintext');
    parcel_check(Settings::decrypt($encrypted) === $secret, 'secret AES-GCM roundtrip');
    $raw = base64_decode(substr($encrypted, 3)); $raw[strlen($raw)-1] = chr(ord($raw[strlen($raw)-1]) ^ 1);
    parcel_reject(fn()=>Settings::decrypt('v1:' . base64_encode($raw)), 'salts');
    parcel_reject(fn()=>Settings::save(['quota_mb'=>127]), 'quota_mb');
    parcel_reject(fn()=>Settings::save(['part_mb'=>46]), 'part_mb');
    parcel_reject(fn()=>Settings::save(['max_backup_mb'=>129]), 'quota');
    parcel_reject(fn()=>Settings::save(['proxy_url'=>'http://name:password@example.org:3128']), 'URL');
    parcel_reject(fn()=>Settings::save(['dns_servers'=>'1.1.1.1:70000']), 'DNS');
    // A late validation failure proves valid DNS syntax without persisting or rescheduling.
    parcel_reject(fn()=>Settings::save(['dns_servers'=>'1.1.1.1:53, [2606:4700:4700::1111]:53','password'=>'short']), '12');
    $public = Settings::public_data();
    foreach (['token','password','proxy_password','_token','_password','_proxy_password'] as $name) { parcel_check(!array_key_exists($name,$public), 'public settings omit ' . $name); }

    $tz = wp_timezone();
    $reference = new DateTimeImmutable('2026-10-19 02:59:00', $tz);
    $daily = array_replace($settings, ['schedule'=>'daily','schedule_time'=>'03:00']);
    parcel_check(Scheduler::next_time($daily,$reference->getTimestamp()) === (new DateTimeImmutable('2026-10-19 03:00:00',$tz))->getTimestamp(), 'daily next wall-clock occurrence');
    parcel_check(Scheduler::next_time($daily,(new DateTimeImmutable('2026-10-19 03:00:00',$tz))->getTimestamp()) === (new DateTimeImmutable('2026-10-20 03:00:00',$tz))->getTimestamp(), 'daily exact occurrence advances to next day');
    $weekly = array_replace($daily,['schedule'=>'weekly','schedule_day'=>0]);
    parcel_check(Scheduler::next_time($weekly,$reference->getTimestamp()) === (new DateTimeImmutable('2026-10-25 03:00:00',$tz))->getTimestamp(), 'weekly uses configured weekday and timezone');
    $hourly = array_replace($daily,['schedule'=>'hourly']);
    parcel_check(Scheduler::next_time($hourly,$reference->getTimestamp()) === (new DateTimeImmutable('2026-10-19 03:00:00',$tz))->getTimestamp(), 'hourly next clock hour');
    parcel_check(Scheduler::next_time(array_replace($daily,['schedule'=>'off']),$reference->getTimestamp()) === null, 'disabled schedule has no next occurrence');

    $foreign = $root . '/foreign-directory'; mkdir($foreign,0755); chmod($foreign,0755);
    file_put_contents($foreign . '/keep.txt','must remain untouched');
    $pipes = [];
    $process = proc_open([PHP_BINARY,__FILE__,'--wp=' . $wpRoot,'--scratch=' . $scratch,'--foreign-child=' . $foreign], [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,['PARCEL_TEST'=>'1']);
    if (!is_resource($process)) { throw new RuntimeException('Cannot start foreign-directory test child'); }
    fclose($pipes[0]); $out=stream_get_contents($pipes[1]); fclose($pipes[1]); $err=stream_get_contents($pipes[2]); fclose($pipes[2]); $exit=proc_close($process);
    parcel_check($exit === 0 && str_starts_with($out,'rejected:'), 'foreign nonempty directory rejected: ' . $out . $err);
    clearstatcache(true,$foreign);
    parcel_check((fileperms($foreign)&0777) === 0755, 'foreign directory permissions unchanged after rejection');
    parcel_check(file_get_contents($foreign . '/keep.txt') === 'must remain untouched', 'foreign directory contents unchanged');
} finally {
    if (is_resource($lock)) { Storage::unlock($lock); }
    remove_filter('pre_option_' . Settings::OPTION, $filter);
    // These assertions detect accidental persistent writes; the suite intentionally makes none.
    parcel_check(get_option(Settings::OPTION,null) === $originalSettings, 'real settings unchanged');
    parcel_check(get_option('cron',null) === $originalCron, 'real cron unchanged');
    parcel_check(get_option('parcel_connection',null) === $originalConnection, 'real connection diagnostics unchanged');
    parcel_fixture_remove($root,$root);
}
echo "Storage, settings and scheduling: {$checks} checks passed. Real options and job history unchanged.\n";
