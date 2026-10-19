<?php
if (!defined('WP_UNINSTALL_PLUGIN')) { exit; }
$parcel_settings=get_option('parcel_settings',[]);
wp_clear_scheduled_hook('parcel_worker');
wp_clear_scheduled_hook('parcel_scheduled_backup');
if (empty($parcel_settings['delete_on_uninstall'])) { return; }
// Backups are deliberately retained even when removing configuration. Never delete Telegram messages.
foreach (['parcel_settings','parcel_worker_state','parcel_connection','parcel_schedule_error','parcel_system_cron'] as $parcel_option) { delete_option($parcel_option); }
