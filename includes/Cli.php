<?php
namespace ParcelBackups;
if (!defined('ABSPATH')) { exit; }
final class Cli {
    public static function init(): void {
        \WP_CLI::add_command('telegram-backup status',static function() { self::run(static fn()=>Api::status()); });
        \WP_CLI::add_command('telegram-backup run',static function() { self::run(static function() { Engine::tick('cli'); return Engine::status(); }); });
        \WP_CLI::add_command('telegram-backup backup',static function($args,$assoc) { self::run(static fn()=>Engine::enqueue($assoc['type']??'full','cli')); });
        \WP_CLI::add_command('telegram-backup check',static function($args,$assoc) { self::run(static fn()=>Engine::preflight($assoc['type']??'full')); });
        \WP_CLI::add_command('telegram-backup cleanup',static function() { self::run(static function() { $lock=Storage::lock(); if (!$lock) { throw new \RuntimeException('Worker busy.'); } try { return Storage::cleanup(); } finally { Storage::unlock($lock); } }); });
        \WP_CLI::add_command('telegram-backup cron status',static function() { self::run(static fn()=>SystemCron::status()); });
        \WP_CLI::add_command('telegram-backup cron install',static function() { self::run(static function() { $status=SystemCron::install(); Scheduler::reschedule(); return $status; }); });
        \WP_CLI::add_command('telegram-backup cron remove',static function() { self::run(static fn()=>SystemCron::remove()); });
    }
    private static function run(callable $fn): void {
        try { \WP_CLI::line(wp_json_encode($fn(),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)); }
        catch (\Throwable $e) { \WP_CLI::error(Support::redact($e->getMessage())); }
    }
}
