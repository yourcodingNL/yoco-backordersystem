<?php
/**
 * Settings Admin Template
 */

if (!defined('ABSPATH')) {
    exit;
}

// Handle database repair
if (isset($_POST['repair_database']) && wp_verify_nonce($_POST['yoco_repair_nonce'], 'yoco_repair_database')) {
    global $wpdb;
    
    $charset_collate = $wpdb->get_charset_collate();
    $errors = array();
    $success = 0;
    
    $tables = array(
        "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}yoco_supplier_settings (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            supplier_term_id bigint(20) unsigned NOT NULL,
            connection_type varchar(10) DEFAULT 'url',
            feed_url text DEFAULT NULL,
            ftp_host varchar(255) DEFAULT '',
            ftp_port int(5) DEFAULT 21,
            ftp_user varchar(255) DEFAULT '',
            ftp_pass varchar(255) DEFAULT '',
            ftp_path varchar(255) DEFAULT '',
            ftp_passive tinyint(1) DEFAULT 1,
            update_frequency int(11) DEFAULT 1,
            update_times text DEFAULT NULL,
            default_delivery_time varchar(255) DEFAULT '',
            csv_delimiter varchar(10) DEFAULT ',',
            csv_has_header tinyint(1) DEFAULT 1,
            sku_column varchar(50) DEFAULT '',
            stock_column varchar(50) DEFAULT '',
            mapping_config text DEFAULT NULL,
            is_active tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY supplier_term_id (supplier_term_id)
        ) $charset_collate;",
        
        "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}yoco_supplier_stock (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            product_id bigint(20) unsigned NOT NULL,
            supplier_term_id bigint(20) unsigned NOT NULL,
            sku varchar(100) DEFAULT '',
            ean varchar(50) DEFAULT '',
            stock_quantity int(11) DEFAULT 0,
            is_available tinyint(1) DEFAULT 0,
            last_updated datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY product_id (product_id),
            KEY supplier_term_id (supplier_term_id),
            KEY sku (sku),
            KEY ean (ean)
        ) $charset_collate;",
        
        "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}yoco_sync_logs (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            supplier_term_id bigint(20) unsigned NOT NULL,
            sync_type varchar(20) DEFAULT 'manual',
            status varchar(20) DEFAULT 'pending',
            products_processed int(11) DEFAULT 0,
            products_updated int(11) DEFAULT 0,
            errors text DEFAULT NULL,
            started_at datetime DEFAULT CURRENT_TIMESTAMP,
            completed_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY supplier_term_id (supplier_term_id),
            KEY status (status)
        ) $charset_collate;",
    );
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    
    foreach ($tables as $table_sql) {
        $result = $wpdb->query($table_sql);
        if ($result !== false) {
            $success++;
        } else {
            $errors[] = 'Failed to create table: ' . $wpdb->last_error;
        }
    }
    
    if ($success == 3 && empty($errors)) {
        echo '<div class="notice notice-success"><p><strong>✅ Database repair successful! All tables created.</strong></p></div>';
    } else {
        echo '<div class="notice notice-error"><p><strong>❌ Database repair failed!</strong><br>';
        foreach ($errors as $error) {
            echo esc_html($error) . '<br>';
        }
        echo '</p></div>';
    }
}

// Handle form submission
if (isset($_POST['save_settings']) && wp_verify_nonce($_POST['yoco_settings_nonce'], 'yoco_save_settings')) {
    $old_cron_enabled = get_option('yoco_cron_enabled', 'no');
    $new_cron_enabled = isset($_POST['yoco_cron_enabled']) ? 'yes' : 'no';
    $old_test_mode = get_option('yoco_cron_test_mode', 'no');
    $new_test_mode = isset($_POST['yoco_cron_test_mode']) ? 'yes' : 'no';
    
    update_option('yoco_enable_frontend_display', isset($_POST['yoco_enable_frontend_display']) ? 'yes' : 'no');
    update_option('yoco_frontend_text', sanitize_text_field($_POST['yoco_frontend_text']));
    update_option('yoco_cron_enabled', $new_cron_enabled);
    update_option('yoco_cron_test_mode', $new_test_mode);
    
    // Test mode interval
    $test_interval = isset($_POST['yoco_cron_test_interval']) ? intval($_POST['yoco_cron_test_interval']) : 5;
    update_option('yoco_cron_test_interval', $test_interval);
    
    // Sync times
    if (isset($_POST['yoco_sync_times']) && is_array($_POST['yoco_sync_times'])) {
        $sync_times = array_map('sanitize_text_field', $_POST['yoco_sync_times']);
        $sync_times = array_filter($sync_times); // Remove empty
        update_option('yoco_sync_times', $sync_times);
    }
    
    update_option('yoco_debug_mode', isset($_POST['yoco_debug_mode']) ? 'yes' : 'no');
    update_option('yoco_auto_sync_on_save', isset($_POST['yoco_auto_sync_on_save']) ? 'yes' : 'no');
    
    // Handle cron scheduling changes
    if ($old_cron_enabled !== $new_cron_enabled || $old_test_mode !== $new_test_mode) {
        if (class_exists('YoCo_Cron') && method_exists('YoCo_Cron', 'maybe_schedule_actions')) {
            YoCo_Cron::maybe_schedule_actions();
            
            if ($new_cron_enabled === 'yes') {
                echo '<div class="notice notice-success"><p>' . __('Automatic synchronization enabled and scheduled via Action Scheduler.', 'yoco-backorder') . '</p></div>';
            } else {
                echo '<div class="notice notice-info"><p>' . __('Automatic synchronization has been disabled.', 'yoco-backorder') . '</p></div>';
            }
        }
    }
    
    echo '<div class="notice notice-success"><p>' . __('Settings saved successfully.', 'yoco-backorder') . '</p></div>';
}

// Get current settings
$enable_frontend = get_option('yoco_enable_frontend_display', 'no');
$frontend_text = get_option('yoco_frontend_text', __('Available from supplier', 'yoco-backorder'));
$cron_enabled = get_option('yoco_cron_enabled', 'no');
$debug_mode = get_option('yoco_debug_mode', 'no');
$auto_sync_on_save = get_option('yoco_auto_sync_on_save', 'no');
$sync_times = get_option('yoco_sync_times', array('03:00'));
?>

<div class="wrap">
    <h1><?php _e('YoCo Backorder Settings', 'yoco-backorder'); ?></h1>
    
    <!-- Database Actions - OUTSIDE main form -->
    <div class="card" style="margin-top: 20px;">
        <h2><?php _e('Database Actions', 'yoco-backorder'); ?></h2>
        <p><?php _e('Use these actions to repair or clean up the database.', 'yoco-backorder'); ?></p>
        
        <form method="post" action="" style="margin-top: 15px;">
            <?php wp_nonce_field('yoco_repair_database', 'yoco_repair_nonce'); ?>
            <button type="submit" name="repair_database" class="button button-secondary">
                <?php _e('Repair Database Tables', 'yoco-backorder'); ?>
            </button>
            <p class="description"><?php _e('Recreates missing database tables if needed.', 'yoco-backorder'); ?></p>
        </form>
    </div>
    
    <!-- Main Settings Form -->
    <form method="post" action="">
        <?php wp_nonce_field('yoco_save_settings', 'yoco_settings_nonce'); ?>
        
        <div class="card">
            <h2><?php _e('Frontend Display', 'yoco-backorder'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Enable Frontend Display', 'yoco-backorder'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="yoco_enable_frontend_display" value="yes" <?php checked($enable_frontend, 'yes'); ?>>
                            <?php _e('Show supplier stock information on product pages', 'yoco-backorder'); ?>
                        </label>
                        <p class="description">
                            <?php _e('When enabled, customers will see supplier availability information on product pages.', 'yoco-backorder'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="yoco_frontend_text"><?php _e('Frontend Text', 'yoco-backorder'); ?></label>
                    </th>
                    <td>
                        <input type="text" id="yoco_frontend_text" name="yoco_frontend_text" value="<?php echo esc_attr($frontend_text); ?>" class="regular-text">
                        <p class="description">
                            <?php _e('Text to display when product is available from supplier.', 'yoco-backorder'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>
        
        <div class="card">
            <h2><?php _e('Automation', 'yoco-backorder'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Automatic Synchronization', 'yoco-backorder'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="yoco_cron_enabled" value="yes" <?php checked($cron_enabled, 'yes'); ?>>
                            <?php _e('Enable automatic supplier synchronization', 'yoco-backorder'); ?>
                        </label>
                        <p class="description">
                            <?php _e('When enabled, suppliers will be synchronized automatically via Action Scheduler.', 'yoco-backorder'); ?>
                        </p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><?php _e('Sync Times', 'yoco-backorder'); ?></th>
                    <td>
                        <div id="sync-times-container">
                            <?php foreach ($sync_times as $index => $time): ?>
                                <div class="sync-time-row" style="margin: 5px 0;">
                                    <input type="time" name="yoco_sync_times[]" value="<?php echo esc_attr($time); ?>" style="width: 120px;">
                                    <button type="button" class="button button-small remove-sync-time" style="margin-left: 5px;"><?php _e('Remove', 'yoco-backorder'); ?></button>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" id="add-sync-time" class="button button-secondary" style="margin-top: 10px;">
                            <?php _e('Add Time', 'yoco-backorder'); ?>
                        </button>
                        <p class="description">
                            <?php _e('Daily sync times in your local timezone. Current server time:', 'yoco-backorder'); ?>
                            <strong><?php echo current_time('H:i'); ?></strong>
                        </p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><?php _e('Cron Test Mode', 'yoco-backorder'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="yoco_cron_test_mode" value="yes" <?php checked(get_option('yoco_cron_test_mode', 'no'), 'yes'); ?>>
                            <?php _e('Enable cron test mode (for development/testing)', 'yoco-backorder'); ?>
                        </label>
                        <p class="description">
                            <?php _e('⚠️ When enabled, cron will run every X minutes instead of daily. Disable after testing!', 'yoco-backorder'); ?>
                        </p>
                        
                        <?php if (get_option('yoco_cron_test_mode', 'no') === 'yes'): ?>
                            <div style="margin-top: 10px;">
                                <label for="yoco_cron_test_interval"><?php _e('Test interval (minutes):', 'yoco-backorder'); ?></label>
                                <input type="number" id="yoco_cron_test_interval" name="yoco_cron_test_interval" value="<?php echo get_option('yoco_cron_test_interval', 5); ?>" min="1" max="60" style="width: 80px;">
                                <span class="description"><?php _e('Run sync every X minutes (1-60)', 'yoco-backorder'); ?></span>
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row"><?php _e('Auto Sync on Product Save', 'yoco-backorder'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="yoco_auto_sync_on_save" value="yes" <?php checked($auto_sync_on_save, 'yes'); ?>>
                            <?php _e('Automatically check supplier stock when saving products', 'yoco-backorder'); ?>
                        </label>
                        <p class="description">
                            <?php _e('⚠️ Warning: Disable this to save hosting resources. When disabled, you need to manually sync suppliers to update stock.', 'yoco-backorder'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>
        
        <div class="card">
            <h2><?php _e('System Information', 'yoco-backorder'); ?></h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th width="200"><?php _e('Check', 'yoco-backorder'); ?></th>
                        <th width="150"><?php _e('Status', 'yoco-backorder'); ?></th>
                        <th><?php _e('Description', 'yoco-backorder'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><?php _e('Current Server Time', 'yoco-backorder'); ?></td>
                        <td>
                            <strong><?php echo current_time('H:i:s'); ?></strong>
                        </td>
                        <td>
                            <?php echo __('Date:', 'yoco-backorder') . ' ' . current_time('Y-m-d'); ?>
                            <br>
                            <?php echo __('Timezone:', 'yoco-backorder') . ' ' . wp_timezone_string(); ?>
                        </td>
                    </tr>
                    <tr>
                        <td><?php _e('Plugin Version', 'yoco-backorder'); ?></td>
                        <td><strong><?php echo YOCO_BACKORDER_VERSION; ?></strong></td>
                        <td><?php _e('YoCo Backorder System version', 'yoco-backorder'); ?></td>
                    </tr>
                    <tr>
                        <td><?php _e('WooCommerce', 'yoco-backorder'); ?></td>
                        <td>
                            <?php 
                            if (class_exists('WooCommerce')) {
                                echo '<span style="color: #46b450;">✓ ' . __('Active', 'yoco-backorder') . '</span>';
                            } else {
                                echo '<span style="color: #dc3232;">✗ ' . __('Not Active', 'yoco-backorder') . '</span>';
                            }
                            ?>
                        </td>
                        <td><?php _e('Required for plugin functionality', 'yoco-backorder'); ?></td>
                    </tr>
                    <tr>
                        <td><?php _e('Action Scheduler', 'yoco-backorder'); ?></td>
                        <td>
                            <?php
                            if (function_exists('as_schedule_recurring_action')) {
                                echo '<span style="color: #46b450;">✓ ' . __('Available', 'yoco-backorder') . '</span>';
                                
                                if (class_exists('YoCo_Cron') && method_exists('YoCo_Cron', 'get_scheduler_status')) {
                                    $status = YoCo_Cron::get_scheduler_status();
                                    
                                    if ($status['has_scheduled']) {
                                        echo '<br><small style="color: #0073aa;">';
                                        echo $status['pending_actions'] . ' pending';
                                        if ($status['running_actions'] > 0) {
                                            echo ', ' . $status['running_actions'] . ' running';
                                        }
                                        echo '</small>';
                                    }
                                }
                            } else {
                                echo '<span style="color: #dc3232;">✗ ' . __('Not Available', 'yoco-backorder') . '</span>';
                            }
                            ?>
                        </td>
                        <td>
                            <?php _e('WooCommerce Action Scheduler for reliable cron', 'yoco-backorder'); ?>
                            <?php if (function_exists('as_schedule_recurring_action')): ?>
                                <br><a href="<?php echo admin_url('tools.php?page=action-scheduler'); ?>" target="_blank">View Scheduled Actions →</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><?php _e('Next Scheduled Sync', 'yoco-backorder'); ?></td>
                        <td>
                            <?php
                            $cron_enabled_check = get_option('yoco_cron_enabled', 'no') === 'yes';
                            if (!$cron_enabled_check) {
                                echo '<span style="color: #666;">—</span>';
                            } else {
                                if (class_exists('YoCo_Cron') && method_exists('YoCo_Cron', 'get_next_scheduled_run')) {
                                    $next_run = YoCo_Cron::get_next_scheduled_run();
                                    if ($next_run) {
                                        $time_diff = $next_run['timestamp'] - current_time('timestamp');
                                        $hours = floor($time_diff / 3600);
                                        $minutes = floor(($time_diff % 3600) / 60);
                                        
                                        echo '<span style="color: #0073aa;">📅 ' . esc_html($next_run['human']) . '</span>';
                                        
                                        if ($next_run['mode'] === 'test') {
                                            if ($time_diff > 0) {
                                                echo '<br><small>In ' . $hours . 'h ' . $minutes . 'm</small>';
                                            } else {
                                                echo '<br><small>Running now</small>';
                                            }
                                        }
                                    } else {
                                        echo '<span style="color: #ffb900;">⚠ Not scheduled</span>';
                                    }
                                } else {
                                    echo '<span style="color: #dc3232;">⚠ Unable to check</span>';
                                }
                            }
                            ?>
                        </td>
                        <td>
                            <?php 
                            if (!$cron_enabled_check) {
                                _e('Enable automatic sync to see schedule', 'yoco-backorder');
                            } else {
                                _e('Next automatic synchronization time', 'yoco-backorder');
                                
                                // Show last sync
                                $test_mode = get_option('yoco_cron_test_mode', 'no') === 'yes';
                                $last_sync = $test_mode 
                                    ? get_option('yoco_last_test_sync', 0)
                                    : get_option('yoco_last_daily_sync', 0);
                                    
                                if ($last_sync > 0) {
                                    echo '<br><small style="color: #666;">Last sync: ' . wp_date('Y-m-d H:i', $last_sync) . '</small>';
                                }
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <td><?php _e('Database Tables', 'yoco-backorder'); ?></td>
                        <td>
                            <?php
                            global $wpdb;
                            $required_tables = array(
                                $wpdb->prefix . 'yoco_supplier_settings',
                                $wpdb->prefix . 'yoco_supplier_stock',
                                $wpdb->prefix . 'yoco_sync_logs'
                            );
                            
                            $all_exist = true;
                            foreach ($required_tables as $table) {
                                $exists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'");
                                if (!$exists) {
                                    $all_exist = false;
                                    break;
                                }
                            }
                            
                            if ($all_exist) {
                                echo '<span style="color: #46b450;">✓ All tables exist</span>';
                            } else {
                                echo '<span style="color: #dc3232;">✗ Missing tables</span>';
                            }
                            ?>
                        </td>
                        <td>
                            <?php _e('Required database tables for plugin', 'yoco-backorder'); ?>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        
        <p class="submit">
            <input type="submit" name="save_settings" class="button button-primary" value="<?php esc_attr_e('Save Settings', 'yoco-backorder'); ?>">
        </p>
    </form>
</div>

<script>
jQuery(document).ready(function($) {
    // Add sync time
    $('#add-sync-time').on('click', function() {
        var html = '<div class="sync-time-row" style="margin: 5px 0;">' +
            '<input type="time" name="yoco_sync_times[]" value="" style="width: 120px;">' +
            '<button type="button" class="button button-small remove-sync-time" style="margin-left: 5px;"><?php _e('Remove', 'yoco-backorder'); ?></button>' +
            '</div>';
        $('#sync-times-container').append(html);
    });
    
    // Remove sync time
    $(document).on('click', '.remove-sync-time', function() {
        $(this).closest('.sync-time-row').remove();
    });
});
</script>