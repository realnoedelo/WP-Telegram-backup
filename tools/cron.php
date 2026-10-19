#!/usr/bin/env php
<?php
/** Standalone cron entry point. Executes only Telegram Backup's due WordPress hooks. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

$lock = null;
$telegramBackupCronToken = null;
$telegramBackupCronExit = 0;

function telegram_backup_fresh_cron_lock() {
    if (wp_using_ext_object_cache()) { return wp_cache_get('doing_cron', 'transient', true); }
    global $wpdb;
    return $wpdb->get_var("SELECT option_value FROM {$wpdb->options} WHERE option_name = '_transient_doing_cron' LIMIT 1");
}
try {
    if (!function_exists('posix_geteuid') || posix_geteuid() === 0) { throw new RuntimeException('Run this worker as the non-root PHP site user.'); }
    $telegramBackupCronArgs = getopt('', ['root:', 'site:', 'check']);
    if (!is_string($telegramBackupCronArgs['root'] ?? null) || !is_string($telegramBackupCronArgs['site'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $telegramBackupCronArgs['site'])) {
        throw new RuntimeException('Required arguments: --root=/absolute/wordpress/path --site=SITE_HASH.');
    }
    $telegramBackupCronRoot = realpath($telegramBackupCronArgs['root']);
    if ($telegramBackupCronRoot === false || !is_dir($telegramBackupCronRoot) || $telegramBackupCronArgs['root'][0] !== '/' || preg_match('/[%\x00-\x1f\x7f]/', $telegramBackupCronRoot)) { throw new RuntimeException('Invalid WordPress root.'); }
    $telegramBackupCronBootstrap = $telegramBackupCronRoot . '/wp-load.php';
    if (!is_file($telegramBackupCronBootstrap) || !is_readable($telegramBackupCronBootstrap) || is_link($telegramBackupCronBootstrap) || realpath($telegramBackupCronBootstrap) !== $telegramBackupCronBootstrap) { throw new RuntimeException('Expected a regular wp-load.php in the specified root.'); }
    $telegramBackupCronPlugin = realpath(dirname(__DIR__) . '/telegram-backup.php');
    if ($telegramBackupCronPlugin === false) { throw new RuntimeException('Plugin installation is incomplete.'); }
    define('DOING_CRON', true);
    define('WP_USE_THEMES', false);
    require $telegramBackupCronBootstrap;
    if (!defined('ABSPATH') || realpath(ABSPATH) !== $telegramBackupCronRoot || !defined('PARCEL_FILE') || realpath(PARCEL_FILE) !== $telegramBackupCronPlugin ||
        !class_exists('ParcelBackups\\SystemCron') || !class_exists('ParcelBackups\\Scheduler')) {
        throw new RuntimeException('This plugin is not active in the specified WordPress installation.');
    }
    if (!in_array(plugin_basename(PARCEL_FILE), (array) get_option('active_plugins', []), true)) { throw new RuntimeException('Telegram Backup must be an active site plugin.'); }
    if (!hash_equals(\ParcelBackups\SystemCron::site_id(), $telegramBackupCronArgs['site'])) { throw new RuntimeException('The site identity does not match this cron entry.'); }
    if (!defined('TELEGRAM_BACKUP_STORAGE_DIR') && !defined('PARCEL_STORAGE_DIR')) { throw new RuntimeException('Persistent TELEGRAM_BACKUP_STORAGE_DIR is required for the system worker.'); }
    $storage = \ParcelBackups\Storage::root();
    if (isset($telegramBackupCronArgs['check'])) {
        if (PHP_VERSION_ID < 80100 || PHP_INT_SIZE < 8 || !extension_loaded('curl') || !extension_loaded('openssl') || !extension_loaded('zlib')) {
            throw new RuntimeException('PHP CLI needs version 8.1+, 64 bit, curl, openssl and zlib.');
        }
        echo json_encode(['ok' => true, 'site' => \ParcelBackups\SystemCron::site_id(), 'storage' => $storage,
            'uid' => posix_geteuid(), 'plugin' => $telegramBackupCronPlugin], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        exit(0);
    }
    $path = $storage . '/system-cron-run.lock';
    if (is_link($path)) { throw new RuntimeException('Refusing a symlink worker lock.'); }
    $lock = fopen($path, 'c+');
    if (!$lock) { throw new RuntimeException('Cannot open system worker lock.'); }
    chmod($path, 0600);
    if (!flock($lock, LOCK_EX | LOCK_NB)) { fclose($lock); $lock = null; exit(0); }
    // Cooperate with native wp-cron. The file lock additionally serializes our own long CLI runs.
    $existingCronLock = telegram_backup_fresh_cron_lock();
    if ($existingCronLock && (float) $existingCronLock + WP_CRON_LOCK_TIMEOUT > microtime(true)) { return; }
    $telegramBackupCronToken = sprintf('%.22F', microtime(true));
    $GLOBALS['doing_wp_cron'] = $telegramBackupCronToken;
    set_transient('doing_cron', $telegramBackupCronToken);
    if ((string) telegram_backup_fresh_cron_lock() !== $telegramBackupCronToken) { return; }
    \ParcelBackups\Scheduler::init();
    $hooks = ['parcel_scheduled_backup', 'parcel_worker'];
    foreach (wp_get_ready_cron_jobs() as $timestamp => $events) {
        foreach ($hooks as $hook) {
            foreach ($events[$hook] ?? [] as $event) {
                if ((string) telegram_backup_fresh_cron_lock() !== $telegramBackupCronToken) { break 3; }
                $eventArgs = $event['args'];
                if ($event['schedule']) {
                    $result = wp_reschedule_event($timestamp, $event['schedule'], $hook, $eventArgs, true);
                    if (is_wp_error($result)) { throw new RuntimeException($result->get_error_message()); }
                }
                $result = wp_unschedule_event($timestamp, $hook, $eventArgs, true);
                if (is_wp_error($result)) { throw new RuntimeException($result->get_error_message()); }
                do_action_ref_array($hook, $eventArgs);
            }
        }
    }
} catch (Throwable $e) {
    $message = class_exists('ParcelBackups\\Support') ? \ParcelBackups\Support::redact($e->getMessage()) : 'System cron: ' . $e->getMessage();
    if (class_exists('ParcelBackups\\Support')) { \ParcelBackups\Support::log('', 'error', $message); }
    fwrite(STDERR, $message . "\n");
    $telegramBackupCronExit = 1;
} finally {
    if ($telegramBackupCronToken !== null && function_exists('wp_using_ext_object_cache') &&
        (string) telegram_backup_fresh_cron_lock() === $telegramBackupCronToken) { delete_transient('doing_cron'); }
    if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
}

exit($telegramBackupCronExit);
