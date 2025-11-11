<?php
/**
 * YoCo Cron Handler
 */

if (!defined('ABSPATH')) {
    exit;
}

class YoCo_Cron {
    
    /**
     * Initialize cron
     */
    public static function init() {
        add_action('yoco_supplier_sync_cron', array(__CLASS__, 'sync_suppliers_cron'));
        add_action('wp', array(__CLASS__, 'schedule_events'));
        add_action('yoco_cron_enabled_updated', array(__CLASS__, 'handle_cron_toggle'));
    }
    
    /**
     * Schedule cron events
     */
    public static function schedule_events() {
        error_log("YOCO CRON: schedule_events() called");
        
        // Only run if cron is enabled
        if (get_option('yoco_cron_enabled') !== 'yes') {
            error_log("YOCO CRON: Not scheduling - cron not enabled");
            return;
        }
        
        // Check if already scheduled
        $next_scheduled = wp_next_scheduled('yoco_supplier_sync_cron');
        if (!$next_scheduled) {
            // Schedule to run every minute to check for sync times
            $scheduled = wp_schedule_event(time(), 'yoco_minutely', 'yoco_supplier_sync_cron');
            error_log("YOCO CRON: Scheduling minutely event - result: " . ($scheduled === false ? 'FAILED' : 'SUCCESS'));
            
            // Double check it was scheduled
            $verify = wp_next_scheduled('yoco_supplier_sync_cron');
            error_log("YOCO CRON: Verification - next scheduled: " . ($verify ? wp_date('H:i:s', $verify) : 'NONE'));
        } else {
            error_log("YOCO CRON: Already scheduled for: " . wp_date('H:i:s', $next_scheduled));
        }
    }
    
    /**
     * Handle cron enable/disable toggle
     */
    public static function handle_cron_toggle() {
        if (get_option('yoco_cron_enabled') === 'yes') {
            self::schedule_events();
        } else {
            self::unschedule_events();
        }
    }
    
    /**
     * Unschedule cron events
     */
    public static function unschedule_events() {
        wp_clear_scheduled_hook('yoco_supplier_sync_cron');
    }
    
    /**
     * Main cron job - runs every minute
     */
    public static function sync_suppliers_cron() {
        // ALWAYS LOG THAT FUNCTION IS CALLED
        error_log("YOCO CRON: Function called at " . current_time('H:i:s'));
        
        // Only run if cron is enabled
        if (get_option('yoco_cron_enabled') !== 'yes') {
            error_log("YOCO CRON: Exiting - cron not enabled");
            return;
        }
        
        // Check if we're in test mode
        $test_mode = get_option('yoco_cron_test_mode', 'no') === 'yes';
        
        // USE WORDPRESS TIMEZONE FOR CONSISTENCY
        $wp_timezone = wp_timezone();
        $current_datetime = new DateTime('now', $wp_timezone);
        $current_time = $current_datetime->format('H:i');
        $current_day = $current_datetime->format('w'); // 0=Sunday, 6=Saturday
        
        if ($test_mode) {
            // TEST MODE: Check if enough time has passed since last sync
            $test_interval = get_option('yoco_cron_test_interval', 5); // minutes
            $last_test_sync = get_option('yoco_last_test_sync', 0);
            $current_timestamp = $current_datetime->getTimestamp();
            
            $time_since_last = $current_timestamp - $last_test_sync;
            $required_interval = $test_interval * 60;
            
            error_log("YOCO CRON TEST MODE: Check timing - Last: " . ($last_test_sync > 0 ? wp_date('H:i:s', $last_test_sync) : 'NEVER') . 
                     ", Current: " . $current_datetime->format('H:i:s') . 
                     ", Since last: " . $time_since_last . "s, Required: " . $required_interval . "s");
            
            if ($time_since_last < $required_interval) {
                error_log("YOCO CRON TEST MODE: Not time yet - need " . ($required_interval - $time_since_last) . " more seconds");
                return; // Not time for test sync yet
            }
            
            // Update last test sync time
            update_option('yoco_last_test_sync', $current_timestamp);
            
            error_log("YOCO CRON TEST MODE: Running sync every {$test_interval} minutes at {$current_time} (WordPress timezone)");
        } else {
            // NORMAL MODE: Check scheduled times
            error_log("YOCO CRON: Checking sync times at {$current_time} (WordPress timezone)");
        }
        
        // Get all suppliers with configured feeds
        $suppliers = YoCo_Supplier::get_suppliers();
        $synced_suppliers = array();
        
        foreach ($suppliers as $supplier) {
            // Skip if not active
            if (!$supplier['settings']['is_active']) {
                continue;
            }
            
            // Skip if no feed configuration
            $has_feed_config = false;
            if (!empty($supplier['settings']['feed_url'])) {
                $has_feed_config = true; // URL mode
            } elseif ($supplier['settings']['connection_type'] === 'ftp' && 
                     !empty($supplier['settings']['ftp_host']) && 
                     !empty($supplier['settings']['ftp_user']) && 
                     !empty($supplier['settings']['ftp_path'])) {
                $has_feed_config = true; // FTP mode
            }
            
            if (!$has_feed_config) {
                continue;
            }
            
            // Decide if we should sync
            $should_sync = false;
            
            if ($test_mode) {
                // TEST MODE: Sync all active suppliers with feeds
                $should_sync = true;
                error_log("YOCO CRON TEST MODE: Syncing supplier {$supplier['name']} (test mode active)");
            } else {
                // NORMAL MODE: Check if it's time to sync this supplier
                $should_sync = self::should_sync_supplier($supplier['settings'], $current_time, $current_day);
                if ($should_sync) {
                    error_log("YOCO CRON: Syncing supplier {$supplier['name']} at scheduled time {$current_time}");
                }
            }
            
            if ($should_sync) {
                try {
                    $result = YoCo_Sync::manual_sync($supplier['term_id']);
                    
                    if ($result['success']) {
                        $synced_suppliers[] = array(
                            'name' => $supplier['name'],
                            'processed' => $result['data']['processed'],
                            'updated' => $result['data']['updated']
                        );
                        error_log("YOCO CRON: Successfully synced {$supplier['name']} - Processed: {$result['data']['processed']}, Updated: {$result['data']['updated']}");
                    } else {
                        error_log("YOCO CRON: Failed to sync {$supplier['name']}: {$result['message']}");
                    }
                } catch (Exception $e) {
                    error_log("YOCO CRON: Exception syncing {$supplier['name']}: " . $e->getMessage());
                }
                
                // Add small delay between suppliers to prevent server overload
                sleep(2);
            }
        }
        
        // Log summary if any suppliers were synced
        if (!empty($synced_suppliers)) {
            $total_processed = array_sum(array_column($synced_suppliers, 'processed'));
            $total_updated = array_sum(array_column($synced_suppliers, 'updated'));
            $mode_text = $test_mode ? 'TEST MODE' : 'NORMAL MODE';
            error_log("YOCO CRON {$mode_text}: Completed sync of " . count($synced_suppliers) . " suppliers. Total processed: {$total_processed}, Total updated: {$total_updated}");
            
            // ALSO LOG TO WORDPRESS OPTIONS FOR DASHBOARD DISPLAY
            $cron_log_entry = array(
                'timestamp' => current_time('timestamp'),
                'datetime' => current_time('Y-m-d H:i:s'),
                'mode' => $test_mode ? 'test' : 'normal',
                'suppliers_synced' => count($synced_suppliers),
                'total_processed' => $total_processed,
                'total_updated' => $total_updated,
                'suppliers' => array_column($synced_suppliers, 'name')
            );
            
            // Keep last 20 cron runs
            $cron_history = get_option('yoco_cron_history', array());
            array_unshift($cron_history, $cron_log_entry);
            $cron_history = array_slice($cron_history, 0, 20);
            update_option('yoco_cron_history', $cron_history);
        }
    }
    
    /**
     * Check if supplier should be synced at current time
     */
    private static function should_sync_supplier($settings, $current_time, $current_day) {
        if (empty($settings['update_times']) || empty($settings['update_frequency'])) {
            return false;
        }
        
        // Parse update times
        $update_times = is_array($settings['update_times']) ? $settings['update_times'] : json_decode($settings['update_times'], true);
        if (!is_array($update_times)) {
            return false;
        }
        
        // Check if current time matches any of the update times
        $time_match = false;
        foreach ($update_times as $update_time) {
            if ($update_time === $current_time) {
                $time_match = true;
                break;
            }
        }
        
        if (!$time_match) {
            return false;
        }
        
        // Check frequency (daily = 1, weekly = 7, etc.)
        $frequency = intval($settings['update_frequency']);
        
        if ($frequency === 1) {
            // Daily - sync every day at specified times
            return true;
        }
        
        if ($frequency === 7) {
            // Weekly - only sync on Mondays (day 1)
            return $current_day === 1;
        }
        
        // For other frequencies, implement as needed
        // For now, default to daily behavior
        return true;
    }
    
    /**
     * Add custom cron schedule
     */
    public static function add_cron_schedules($schedules) {
        $schedules['yoco_minutely'] = array(
            'interval' => 60,
            'display' => __('Every Minute (YoCo)', 'yoco-backorder')
        );
        
        return $schedules;
    }
    
    /**
     * Get next scheduled sync for a supplier
     */
    public static function get_next_sync_time($supplier_settings) {
        if (empty($supplier_settings['update_times'])) {
            return null;
        }
        
        $current_time = current_time('H:i');
        $current_timestamp = current_time('timestamp');
        
        $update_times = is_array($supplier_settings['update_times']) ? 
            $supplier_settings['update_times'] : 
            json_decode($supplier_settings['update_times'], true);
        
        if (!is_array($update_times)) {
            return null;
        }
        
        // Find next sync time today
        $today_date = current_time('Y-m-d');
        $next_time = null;
        
        foreach ($update_times as $time) {
            $sync_timestamp = strtotime($today_date . ' ' . $time);
            
            if ($sync_timestamp > $current_timestamp) {
                if ($next_time === null || $sync_timestamp < $next_time) {
                    $next_time = $sync_timestamp;
                }
            }
        }
        
        // If no time found today, use first time tomorrow
        if ($next_time === null) {
            $tomorrow_date = date('Y-m-d', strtotime('+1 day', $current_timestamp));
            $first_time = reset($update_times);
            $next_time = strtotime($tomorrow_date . ' ' . $first_time);
        }
        
        return $next_time;
    }
}

// Add custom cron schedule
add_filter('cron_schedules', array('YoCo_Cron', 'add_cron_schedules'));

// Initialize cron
YoCo_Cron::init();