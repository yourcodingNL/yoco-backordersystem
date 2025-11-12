<?php
/**
 * YoCo Cron Handler - Fixed timezone handling
 */

if (!defined('ABSPATH')) {
    exit;
}

class YoCo_Cron {
    
    const DAILY_SYNC_HOOK = 'yoco_daily_supplier_sync';
    const TEST_SYNC_HOOK = 'yoco_test_supplier_sync';
    const GROUP = 'yoco-backorder-sync';
    
    public static function init() {
        add_action(self::DAILY_SYNC_HOOK, array(__CLASS__, 'run_daily_sync'));
        add_action(self::TEST_SYNC_HOOK, array(__CLASS__, 'run_test_sync'));
        add_action('init', array(__CLASS__, 'maybe_schedule_actions'), 20);
        
        // Force Action Scheduler to process queue
        add_action('action_scheduler_run_queue', array(__CLASS__, 'ensure_runner_active'));
    }
    
    /**
     * Ensure Action Scheduler runner is active
     */
    public static function ensure_runner_active() {
        if (!function_exists('ActionScheduler_QueueRunner')) {
            return;
        }
        
        // This forces WooCommerce to check for due actions
        if (class_exists('ActionScheduler_QueueRunner')) {
            ActionScheduler_QueueRunner::instance()->run();
        }
    }
    
    public static function maybe_schedule_actions() {
        if (!function_exists('as_has_scheduled_action')) {
            return;
        }
        
        // Prevent duplicate scheduling in same request
        static $already_scheduled = false;
        if ($already_scheduled) {
            return;
        }
        $already_scheduled = true;
        
        $cron_enabled = get_option('yoco_cron_enabled', 'no') === 'yes';
        $test_mode = get_option('yoco_cron_test_mode', 'no') === 'yes';
        
        if (!$cron_enabled) {
            self::unschedule_all_actions();
            return;
        }
        
        if ($test_mode) {
            self::schedule_test_mode();
        } else {
            self::schedule_daily_syncs();
        }
    }
    
    private static function schedule_daily_syncs() {
        // Unschedule test mode
        as_unschedule_all_actions(self::TEST_SYNC_HOOK, array(), self::GROUP);
        
        $sync_times = get_option('yoco_sync_times', array('03:00'));
        $timezone = wp_timezone();
        $now = new DateTime('now', $timezone);
        $today = $now->format('Y-m-d');
        
        // Get currently scheduled actions
        $existing_actions = as_get_scheduled_actions(array(
            'hook' => self::DAILY_SYNC_HOOK,
            'group' => self::GROUP,
            'status' => ActionScheduler_Store::STATUS_PENDING,
            'per_page' => 100
        ));
        
        // Extract times from existing actions
        $existing_times = array();
        foreach ($existing_actions as $action) {
            $args = $action->get_args();
            if (isset($args['time'])) {
                $existing_times[] = $args['time'];
            }
        }
        
        // Remove actions for times that are no longer configured
        foreach ($existing_actions as $action_id => $action) {
            $args = $action->get_args();
            $time = isset($args['time']) ? $args['time'] : null;
            if ($time && !in_array($time, $sync_times)) {
                as_unschedule_action($action_id);
                error_log("YOCO CRON: Removed old sync time {$time}");
            }
        }
        
        // Schedule new times
        foreach ($sync_times as $time) {
            // Skip if already scheduled
            if (in_array($time, $existing_times)) {
                error_log("YOCO CRON: Sync at {$time} already scheduled, skipping");
                continue;
            }
            
            $sync_dt = new DateTime($today . ' ' . $time, $timezone);
            
            // If passed today, schedule tomorrow
            if ($sync_dt->getTimestamp() <= $now->getTimestamp()) {
                $sync_dt->modify('+1 day');
            }
            
            as_schedule_recurring_action($sync_dt->getTimestamp(), DAY_IN_SECONDS, self::DAILY_SYNC_HOOK, array('time' => $time), self::GROUP);
            error_log("YOCO CRON: Scheduled daily sync at {$time} (" . $sync_dt->format('Y-m-d H:i:s') . ")");
        }
    }
    
    private static function schedule_test_mode() {
        $test_interval = get_option('yoco_cron_test_interval', 5);
        $interval_seconds = $test_interval * 60;
        
        as_unschedule_all_actions(self::DAILY_SYNC_HOOK, array(), self::GROUP);
        
        if (as_has_scheduled_action(self::TEST_SYNC_HOOK, array(), self::GROUP)) {
            return;
        }
        
        $first_run = time() + 60;
        as_schedule_recurring_action($first_run, $interval_seconds, self::TEST_SYNC_HOOK, array(), self::GROUP);
        error_log("YOCO CRON: Scheduled test sync every {$test_interval} minutes");
    }
    
    public static function unschedule_all_actions() {
        if (!function_exists('as_unschedule_all_actions')) {
            return;
        }
        
        as_unschedule_all_actions(self::DAILY_SYNC_HOOK, array(), self::GROUP);
        as_unschedule_all_actions(self::TEST_SYNC_HOOK, array(), self::GROUP);
    }
    
    public static function run_daily_sync($args = array()) {
        $time = isset($args['time']) ? $args['time'] : 'unknown';
        error_log("YOCO CRON: Daily sync triggered at {$time}");
        self::sync_all_active_suppliers('scheduled');
    }
    
    public static function run_test_sync() {
        error_log('YOCO CRON: Test sync triggered');
        self::sync_all_active_suppliers('test');
    }
    
    private static function sync_all_active_suppliers($mode = 'scheduled') {
        $start_time = microtime(true);
        $suppliers = YoCo_Supplier::get_suppliers();
        $synced_suppliers = array();
        
        foreach ($suppliers as $supplier) {
            if (!$supplier['settings']['is_active']) {
                continue;
            }
            
            $has_feed_config = false;
            if (!empty($supplier['settings']['feed_url'])) {
                $has_feed_config = true;
            } elseif ($supplier['settings']['connection_type'] === 'ftp' && 
                     !empty($supplier['settings']['ftp_host']) && 
                     !empty($supplier['settings']['ftp_user']) && 
                     !empty($supplier['settings']['ftp_path'])) {
                $has_feed_config = true;
            }
            
            if (!$has_feed_config) {
                continue;
            }
            
            try {
                $result = YoCo_Sync::manual_sync($supplier['term_id']);
                
                if ($result['success']) {
                    $synced_suppliers[] = array(
                        'name' => $supplier['name'],
                        'processed' => $result['data']['processed'],
                        'updated' => $result['data']['updated']
                    );
                    error_log("YOCO CRON: Synced {$supplier['name']} - Processed: {$result['data']['processed']}, Updated: {$result['data']['updated']}");
                } else {
                    error_log("YOCO CRON: Failed {$supplier['name']}: {$result['message']}");
                }
            } catch (Exception $e) {
                error_log("YOCO CRON: Exception {$supplier['name']}: " . $e->getMessage());
            }
            
            sleep(2);
        }
        
        if (!empty($synced_suppliers)) {
            $total_processed = array_sum(array_column($synced_suppliers, 'processed'));
            $total_updated = array_sum(array_column($synced_suppliers, 'updated'));
            $duration = round(microtime(true) - $start_time, 2);
            
            error_log("YOCO CRON ({$mode}): Completed " . count($synced_suppliers) . " suppliers in {$duration}s");
            
            $cron_log_entry = array(
                'timestamp' => time(),
                'datetime' => current_time('Y-m-d H:i:s'),
                'mode' => $mode,
                'suppliers_synced' => count($synced_suppliers),
                'total_processed' => $total_processed,
                'total_updated' => $total_updated,
                'duration' => $duration,
                'suppliers' => array_column($synced_suppliers, 'name')
            );
            
            $cron_history = get_option('yoco_cron_history', array());
            array_unshift($cron_history, $cron_log_entry);
            $cron_history = array_slice($cron_history, 0, 20);
            update_option('yoco_cron_history', $cron_history);
            
            if ($mode === 'test') {
                update_option('yoco_last_test_sync', time());
            } else {
                update_option('yoco_last_daily_sync', time());
            }
        }
    }
    
    public static function get_next_scheduled_run() {
        if (!function_exists('as_next_scheduled_action')) {
            return null;
        }
        
        if (get_option('yoco_cron_enabled', 'no') !== 'yes') {
            return null;
        }
        
        $test_mode = get_option('yoco_cron_test_mode', 'no') === 'yes';
        $hook = $test_mode ? self::TEST_SYNC_HOOK : self::DAILY_SYNC_HOOK;
        $next_timestamp = as_next_scheduled_action($hook, array(), self::GROUP);
        
        if (!$next_timestamp) {
            return null;
        }
        
        // Convert UTC timestamp to local time for display
        $timezone = wp_timezone();
        $next_dt = new DateTime('@' . $next_timestamp);
        $next_dt->setTimezone($timezone);
        
        if ($test_mode) {
            return array(
                'timestamp' => $next_timestamp,
                'datetime' => $next_dt->format('Y-m-d H:i:s'),
                'human' => $next_dt->format('H:i'),
                'mode' => 'test',
                'interval' => get_option('yoco_cron_test_interval', 5)
            );
        } else {
            return array(
                'timestamp' => $next_timestamp,
                'datetime' => $next_dt->format('Y-m-d H:i:s'),
                'human' => $next_dt->format('Y-m-d H:i'),
                'mode' => 'scheduled'
            );
        }
    }
    
    public static function get_scheduler_status() {
        if (!function_exists('as_has_scheduled_action')) {
            return array('available' => false, 'message' => 'Not loaded');
        }
        
        $test_mode = get_option('yoco_cron_test_mode', 'no') === 'yes';
        $hook = $test_mode ? self::TEST_SYNC_HOOK : self::DAILY_SYNC_HOOK;
        
        $pending = count(as_get_scheduled_actions(array(
            'group' => self::GROUP,
            'status' => ActionScheduler_Store::STATUS_PENDING,
            'per_page' => 100
        )));
        
        return array(
            'available' => true,
            'enabled' => get_option('yoco_cron_enabled', 'no') === 'yes',
            'test_mode' => $test_mode,
            'has_scheduled' => as_has_scheduled_action($hook, array(), self::GROUP),
            'next_run' => as_next_scheduled_action($hook, array(), self::GROUP),
            'pending_actions' => $pending,
            'running_actions' => 0
        );
    }
    
    public static function trigger_manual_sync() {
        self::sync_all_active_suppliers('manual');
    }
}

YoCo_Cron::init();