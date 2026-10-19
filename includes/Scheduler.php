<?php
namespace ParcelBackups;
if (!defined('ABSPATH')) { exit; }
final class Scheduler {
    private static bool $initialized=false;
    public static function init(): void {
        if (self::$initialized) { return; } self::$initialized=true;
        add_filter('cron_schedules',static function($s) { $s['parcel_minute']=['interval'=>60,'display'=>__('Every minute (TG Backup)','tg-backup')]; return $s; });
        add_action('parcel_worker',static function() {
            try { Engine::tick('cron'); } catch (\Throwable $e) { Support::log('','error',$e->getMessage()); }
        });
        add_action('parcel_scheduled_backup',[self::class,'run']);
        add_action('update_option_timezone_string',[self::class,'reschedule']);
        add_action('update_option_gmt_offset',[self::class,'reschedule']);
        // Do not silently repair missing events on every admin visit: diagnostics should reveal a broken scheduler.
    }
    public static function clear(): void { wp_clear_scheduled_hook('parcel_worker'); wp_clear_scheduled_hook('parcel_scheduled_backup'); }
    public static function next_time(?array $s=null,?int $after=null): ?int {
        $s=$s??Settings::get(); $after=$after??time();
        if ($s['schedule']==='off') { return null; }
        $tz=wp_timezone(); $now=(new \DateTimeImmutable('@'.$after))->setTimezone($tz);
        if ($s['schedule']==='hourly') { return $now->modify('+1 hour')->setTime((int)$now->modify('+1 hour')->format('H'),0)->getTimestamp(); }
        [$h,$m]=array_map('intval',explode(':',$s['schedule_time']));
        $next=$now->setTime($h,$m);
        if ($s['schedule']==='daily') { if ($next->getTimestamp()<=$after) { $next=$next->modify('+1 day'); } }
        else {
            $delta=((int)$s['schedule_day']-(int)$next->format('w')+7)%7;
            if ($delta>0) { $next=$next->modify('+'.$delta.' days'); }
            if ($next->getTimestamp()<=$after) { $next=$next->modify('+7 days'); }
        }
        return $next->getTimestamp();
    }
    public static function reschedule(): void {
        self::init(); self::clear();
        wp_schedule_event(time()+30,'parcel_minute','parcel_worker');
        $next=self::next_time(); if ($next!==null) { wp_schedule_single_event($next,'parcel_scheduled_backup'); }
    }
    public static function run(): void {
        // Schedule the next wall-clock occurrence even if enqueue fails. Missed days never create a storm.
        $next=self::next_time();
        if ($next!==null && !wp_next_scheduled('parcel_scheduled_backup')) { wp_schedule_single_event($next,'parcel_scheduled_backup'); }
        try {
            $s=Settings::get(); if ($s['schedule']==='off') { return; }
            foreach (Storage::jobs() as $job) {
                if (in_array($job['status'],['queued','running','retry'],true)) { Support::log('','info','Scheduled backup skipped: the queue is still busy.'); return; }
            }
            Engine::enqueue($s['backup_type'],'schedule');
            delete_option('parcel_schedule_error');
        } catch (\Throwable $e) {
            $error=['time'=>Support::now(),'message'=>Support::redact($e->getMessage())];
            update_option('parcel_schedule_error',$error,false); Support::log('','error','Could not create the scheduled backup: '.$error['message']);
        }
    }
    public static function status(): array {
        return ['next_run'=>wp_next_scheduled('parcel_scheduled_backup') ?: null,'next_worker'=>wp_next_scheduled('parcel_worker') ?: null,
            'timezone'=>wp_timezone_string(),'wp_cron_disabled'=>defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
            'error'=>get_option('parcel_schedule_error',null)];
    }
}
