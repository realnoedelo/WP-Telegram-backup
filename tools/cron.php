<?php
/** Standalone cron entry point. Executes only TG Backup's due WordPress hooks. */
declare(strict_types=1);
// CLI starts before WordPress; web requests must never run this worker.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    if (!defined('ABSPATH')) {
        exit;
    }
    // Refuse web execution even when WordPress has already loaded.
    exit;
}

$parcel_cron_lock = null;
$parcel_cron_token = null;
$parcel_cron_exit = 0;

function parcel_fresh_cron_lock() {
    if (wp_using_ext_object_cache()) { return wp_cache_get('doing_cron', 'transient', true); }
    global $wpdb;
    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Read the live core cron lock, bypassing stale option caches to detect another worker.
    return $wpdb->get_var($wpdb->prepare('SELECT option_value FROM %i WHERE option_name = %s LIMIT 1', $wpdb->options, '_transient_doing_cron'));
}
try {
    if (!function_exists('posix_geteuid') || posix_geteuid() === 0) { throw new RuntimeException('Run this worker as the non-root PHP site user.'); }
    $parcel_cron_args = getopt('', ['root:', 'site:', 'check']);
    if (!is_string($parcel_cron_args['root'] ?? null) || !is_string($parcel_cron_args['site'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $parcel_cron_args['site'])) {
        throw new RuntimeException('Required arguments: --root=/absolute/wordpress/path --site=SITE_HASH.');
    }
    $parcel_cron_root = realpath($parcel_cron_args['root']);
    if ($parcel_cron_root === false || !is_dir($parcel_cron_root) || $parcel_cron_args['root'][0] !== '/' || preg_match('/[%\x00-\x1f\x7f]/', $parcel_cron_root)) { throw new RuntimeException('Invalid WordPress root.'); }
    $parcel_cron_bootstrap = $parcel_cron_root . '/wp-load.php';
    if (!is_file($parcel_cron_bootstrap) || !is_readable($parcel_cron_bootstrap) || is_link($parcel_cron_bootstrap) || realpath($parcel_cron_bootstrap) !== $parcel_cron_bootstrap) { throw new RuntimeException('Expected a regular wp-load.php in the specified root.'); }
    $parcel_cron_plugin = realpath(dirname(__DIR__) . '/tg-backup.php');
    if ($parcel_cron_plugin === false) { throw new RuntimeException('Plugin installation is incomplete.'); }
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- This exact WordPress core bootstrap/cron symbol is required to cooperate with native WP-Cron.
    define('DOING_CRON', true);
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- This exact WordPress core bootstrap/cron symbol is required to cooperate with native WP-Cron.
    define('WP_USE_THEMES', false);
    require $parcel_cron_bootstrap;
    if (!defined('ABSPATH') || realpath(ABSPATH) !== $parcel_cron_root || !defined('PARCEL_FILE') || realpath(PARCEL_FILE) !== $parcel_cron_plugin ||
        !class_exists('ParcelBackups\\SystemCron') || !class_exists('ParcelBackups\\Scheduler')) {
        throw new RuntimeException('This plugin is not active in the specified WordPress installation.');
    }
    if (!in_array(plugin_basename(PARCEL_FILE), (array) get_option('active_plugins', []), true)) { throw new RuntimeException('TG Backup must be an active site plugin.'); }
    if (!hash_equals(\ParcelBackups\SystemCron::site_id(), $parcel_cron_args['site'])) { throw new RuntimeException('The site identity does not match this cron entry.'); }
    if (!defined('TELEGRAM_BACKUP_STORAGE_DIR') && !defined('PARCEL_STORAGE_DIR')) { throw new RuntimeException('Persistent TELEGRAM_BACKUP_STORAGE_DIR is required for the system worker.'); }
    $parcel_cron_storage = \ParcelBackups\Storage::root();
    if (isset($parcel_cron_args['check'])) {
        if (PHP_VERSION_ID < 80100 || PHP_INT_SIZE < 8 || !extension_loaded('curl') || !extension_loaded('openssl') || !extension_loaded('zlib')) {
            throw new RuntimeException('PHP CLI needs version 8.1+, 64 bit, curl, openssl and zlib.');
        }
        echo json_encode(['ok' => true, 'site' => \ParcelBackups\SystemCron::site_id(), 'storage' => $parcel_cron_storage,
            'uid' => posix_geteuid(), 'plugin' => $parcel_cron_plugin], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        exit(0);
    }
    $parcel_cron_path = $parcel_cron_storage . '/system-cron-run.lock';
    if (is_link($parcel_cron_path)) { throw new RuntimeException('Refusing a symlink worker lock.'); }
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Local streams provide bounded-memory reads, seek/truncate checkpoints and flock; WP_Filesystem has no equivalent handle semantics.
    $parcel_cron_lock = fopen($parcel_cron_path, 'c+');
    if (!$parcel_cron_lock) { throw new RuntimeException('Cannot open system worker lock.'); }
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Owner-only permissions are required for these local private-storage/lock files.
    chmod($parcel_cron_path, 0600);
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streams provide bounded-memory reads, seek/truncate checkpoints and flock; WP_Filesystem has no equivalent handle semantics.
    if (!flock($parcel_cron_lock, LOCK_EX | LOCK_NB)) { fclose($parcel_cron_lock); $parcel_cron_lock = null; exit(0); }
    // Cooperate with native wp-cron. The file lock additionally serializes our own long CLI runs.
    $parcel_cron_existing_cron_lock = parcel_fresh_cron_lock();
    if ($parcel_cron_existing_cron_lock && (float) $parcel_cron_existing_cron_lock + WP_CRON_LOCK_TIMEOUT > microtime(true)) { return; }
    $parcel_cron_token = sprintf('%.22F', microtime(true));
    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- This exact WordPress core bootstrap/cron symbol is required to cooperate with native WP-Cron.
    $GLOBALS['doing_wp_cron'] = $parcel_cron_token;
    set_transient('doing_cron', $parcel_cron_token);
    if ((string) parcel_fresh_cron_lock() !== $parcel_cron_token) { return; }
    \ParcelBackups\Scheduler::init();
    $parcel_cron_hooks = ['parcel_scheduled_backup', 'parcel_worker'];
    foreach (wp_get_ready_cron_jobs() as $parcel_cron_timestamp => $parcel_cron_events) {
        foreach ($parcel_cron_hooks as $parcel_cron_hook) {
            foreach ($parcel_cron_events[$parcel_cron_hook] ?? [] as $parcel_cron_event) {
                if ((string) parcel_fresh_cron_lock() !== $parcel_cron_token) { break 3; }
                $parcel_cron_event_args = $parcel_cron_event['args'];
                if ($parcel_cron_event['schedule']) {
                    $parcel_cron_result = wp_reschedule_event($parcel_cron_timestamp, $parcel_cron_event['schedule'], $parcel_cron_hook, $parcel_cron_event_args, true);
                    if (is_wp_error($parcel_cron_result)) { throw new RuntimeException($parcel_cron_result->get_error_message()); }
                }
                $parcel_cron_result = wp_unschedule_event($parcel_cron_timestamp, $parcel_cron_hook, $parcel_cron_event_args, true);
                if (is_wp_error($parcel_cron_result)) { throw new RuntimeException($parcel_cron_result->get_error_message()); }
                // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- The hook is restricted to the two literal parcel_ hook names above.
                do_action_ref_array($parcel_cron_hook, $parcel_cron_event_args);
            }
        }
    }
} catch (Throwable $parcel_cron_e) {
    $parcel_cron_message = class_exists('ParcelBackups\\Support') ? \ParcelBackups\Support::redact($parcel_cron_e->getMessage()) : 'System cron: ' . $parcel_cron_e->getMessage();
    if (class_exists('ParcelBackups\\Support')) { \ParcelBackups\Support::log('', 'error', $parcel_cron_message); }
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- Local streams provide bounded-memory reads, seek/truncate checkpoints and flock; WP_Filesystem has no equivalent handle semantics.
    fwrite(STDERR, $parcel_cron_message . "\n");
    $parcel_cron_exit = 1;
} finally {
    if ($parcel_cron_token !== null && function_exists('wp_using_ext_object_cache') &&
        (string) parcel_fresh_cron_lock() === $parcel_cron_token) { delete_transient('doing_cron'); }
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Local streams provide bounded-memory reads, seek/truncate checkpoints and flock; WP_Filesystem has no equivalent handle semantics.
    if (is_resource($parcel_cron_lock)) { flock($parcel_cron_lock, LOCK_UN); fclose($parcel_cron_lock); }
}

exit((int) $parcel_cron_exit);
