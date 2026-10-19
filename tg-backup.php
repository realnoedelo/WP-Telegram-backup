<?php
/**
 * Plugin Name: TG Backup
 * Plugin URI: https://github.com/realnoedelo/WP-Telegram-backup
 * Description: Encrypted, resumable WordPress backups to your own Telegram bot. Schedules, storage quotas, proxy and DNS diagnostics included.
 * Version: 1.0.2
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Author: realnoedelo and contributors
 * Author URI: https://github.com/realnoedelo
 * License: GPL-3.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain: tg-backup
 */
if (!defined('ABSPATH')) { exit; }
define('PARCEL_VERSION', '1.0.2');
define('PARCEL_FILE', __FILE__);
define('PARCEL_DIR', plugin_dir_path(__FILE__));
define('PARCEL_URL', plugin_dir_url(__FILE__));
spl_autoload_register(static function ($class) {
    $prefix = 'ParcelBackups\\';
    if (strpos($class, $prefix) !== 0) { return; }
    $name = substr($class, strlen($prefix));
    if (preg_match('/^[A-Za-z]+$/D', $name) && is_file(PARCEL_DIR . 'includes/' . $name . '.php')) {
        require_once PARCEL_DIR . 'includes/' . $name . '.php';
    }
});
register_activation_hook(__FILE__, static function ($network_wide) {
    if (is_multisite() || $network_wide) { wp_die(esc_html__('TG Backup 1.0 supports single-site WordPress only.', 'tg-backup')); }
    \ParcelBackups\Settings::install();
    \ParcelBackups\Scheduler::init();
    \ParcelBackups\Scheduler::reschedule();
});
add_action('activated_plugin', static function ($plugin) {
    if ($plugin === plugin_basename(PARCEL_FILE) && defined('DISABLE_WP_CRON') && DISABLE_WP_CRON) {
        try { \ParcelBackups\SystemCron::install(); }
        catch (\Throwable $e) { \ParcelBackups\Support::log('', 'warning', 'System cron was not installed: ' . $e->getMessage()); }
    }
});
register_deactivation_hook(__FILE__, static function () {
    \ParcelBackups\Scheduler::clear();
    try { \ParcelBackups\SystemCron::remove(); }
    catch (\Throwable $e) { \ParcelBackups\Support::log('', 'warning', 'System cron was not removed: ' . $e->getMessage()); }
});
add_action('plugins_loaded', static function () {
    \ParcelBackups\Scheduler::init();
    \ParcelBackups\Api::init();
    if (is_admin()) { \ParcelBackups\Admin::init(); }
    if (defined('WP_CLI') && WP_CLI) { \ParcelBackups\Cli::init(); }
});
add_filter('plugin_action_links_' . plugin_basename(__FILE__), static function ($links) {
    array_unshift($links, '<a href="' . esc_url(admin_url('options-general.php?page=parcel-backups')) . '">' . esc_html__('Settings', 'tg-backup') . '</a>');
    return $links;
});
