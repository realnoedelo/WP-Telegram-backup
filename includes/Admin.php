<?php
/** WordPress administration screen. */
namespace ParcelBackups;

if (!defined('ABSPATH')) {
    exit;
}

final class Admin
{
    private static string $hook = '';

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'menu']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue']);
    }

    public static function menu(): void
    {
        self::$hook = (string) add_options_page(
            __('TG Backup', 'tg-backup'),
            __('TG Backup', 'tg-backup'),
            'manage_options',
            'parcel-backups',
            [self::class, 'render']
        );
    }

    public static function enqueue(string $hook): void
    {
        if ($hook !== self::$hook || !current_user_can('manage_options')) {
            return;
        }
        wp_enqueue_style('parcel-backups-admin', PARCEL_URL . 'assets/admin.css', [], PARCEL_VERSION);
        wp_enqueue_script('parcel-backups-admin', PARCEL_URL . 'assets/admin.js', [], PARCEL_VERSION, true);
        wp_localize_script('parcel-backups-admin', 'ParcelBackups', [
            'root' => untrailingslashit(rest_url('parcel-backups/v1')),
            'nonce' => wp_create_nonce('wp_rest'),
            'version' => PARCEL_VERSION,
            'locale' => get_user_locale(),
            'cronUrl' => site_url('/wp-cron.php'),
        ]);
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage backups.', 'tg-backup'));
        }
        echo '<div class="wrap parcel-wrap"><div id="parcel-app" class="parcel-app" aria-busy="true"><div class="parcel-loading" role="status">' . esc_html__('Loading TG Backup…', 'tg-backup') . '</div></div><noscript><p>' . esc_html__('Enable JavaScript in your browser to manage this plugin.', 'tg-backup') . '</p></noscript>';
        echo '<p class="description">' . esc_html__('Support TG Backup by realnoedelo', 'tg-backup') . '<br>BTC (Bitcoin): <code>14hr2hSujN6R6rqpEMdazBYQdfTSjV3dwo</code> · USDT (TRON / TRC20): <code>TQNshBHK89R3qccPG6EmjitzoDkuuLSB32</code></p></div>';
    }
}
