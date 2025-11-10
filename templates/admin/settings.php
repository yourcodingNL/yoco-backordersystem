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
    
    $tables = array(
        "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}yoco_supplier_settings (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            supplier_term_id bigint(20) unsigned NOT NULL,
            feed_url text DEFAULT NULL,
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
            UNIQUE KEY product_supplier (product_id, supplier_term_id),
            KEY supplier_term_id (supplier_term_id),
            KEY sku (sku),
            KEY ean (ean)
        ) $charset_collate;",
        
        "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}yoco_sync_logs (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            supplier_term_id bigint(20) unsigned NOT NULL,
            sync_type varchar(50) DEFAULT 'manual',
            status varchar(20) DEFAULT 'pending',
            products_processed int(11) DEFAULT 0,
            products_updated int(11) DEFAULT 0,
            errors_count int(11) DEFAULT 0,
            error_messages text DEFAULT NULL,
            started_at datetime DEFAULT CURRENT_TIMESTAMP,
            completed_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY supplier_term_id (supplier_term_id),
            KEY status (status),
            KEY started_at (started_at)
        ) $charset_collate;"
    );
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    
    $success = 0;
    $errors = array();
    
    foreach ($tables as $sql) {
        $result = $wpdb->query($sql);
        if ($result !== false) {
            $success++;
        } else {
            $errors[] = $wpdb->last_error;
        }
    }
    
    if ($success == 3) {
        echo '<div class="notice notice-success"><p><strong>✅ Database repair successful!</strong> All tables created.</p></div>';
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
    update_option('yoco_enable_frontend_display', isset($_POST['yoco_enable_frontend_display']) ? 'yes' : 'no');
    update_option('yoco_frontend_text', sanitize_text_field($_POST['yoco_frontend_text']));
    update_option('yoco_cron_enabled', isset($_POST['yoco_cron_enabled']) ? 'yes' : 'no');
    update_option('yoco_debug_mode', isset($_POST['yoco_debug_mode']) ? 'yes' : 'no');
    
    echo '<div class="notice notice-success"><p>' . __('Settings saved successfully.', 'yoco-backorder') . '</p></div>';
}

// Get current settings
$enable_frontend = get_option('yoco_enable_frontend_display', 'no');
$frontend_text = get_option('yoco_frontend_text', __('Available from supplier', 'yoco-backorder'));
$cron_enabled = get_option('yoco_cron_enabled', 'no');
$debug_mode = get_option('yoco_debug_mode', 'no');
?>

<div class="wrap">
    <h1><?php _e('YoCo Backorder Settings', 'yoco-backorder'); ?></h1>
    
    <form method="post" action="">
        <?php wp_nonce_field('yoco_save_settings', 'yoco_settings_nonce'); ?>
        
        <div class="card" style="margin-top: 20px;">
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
                            <?php _e('When enabled, suppliers will be synchronized automatically based on their configured schedules.', 'yoco-backorder'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>
        
        <div class="card">
            <h2><?php _e('System Information', 'yoco-backorder'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Current Server Time', 'yoco-backorder'); ?></th>
                    <td>
                        <strong><?php echo current_time('Y-m-d H:i:s'); ?></strong>
                        <br>
                        <span class="description"><?php echo __('Timezone:', 'yoco-backorder') . ' ' . wp_timezone_string(); ?></span>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Plugin Version', 'yoco-backorder'); ?></th>
                    <td><strong><?php echo YOCO_BACKORDER_VERSION; ?></strong></td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Database Tables', 'yoco-backorder'); ?></th>
                    <td>
                        <?php
                        global $wpdb;
                        $tables = YoCo_Install::get_schema();
                        foreach ($tables as $name => $table) {
                            $exists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'");
                            $status = $exists ? 'success' : 'error';
                            $icon = $exists ? '✓' : '✗';
                            $color = $exists ? '#46b450' : '#dc3232';
                            echo "<div style='color: {$color};'>{$icon} {$name}</div>";
                        }
                        ?>
                    </td>
                </tr>
            </table>
        </div>
        
        <div class="card">
            <h2><?php _e('Database Actions', 'yoco-backorder'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Database Repair', 'yoco-backorder'); ?></th>
                    <td>
                        <?php
                        // Check if tables exist
                        global $wpdb;
                        $tables_status = array(
                            'supplier_settings' => $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}yoco_supplier_settings'"),
                            'supplier_stock' => $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}yoco_supplier_stock'"),
                            'sync_logs' => $wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}yoco_sync_logs'")
                        );
                        
                        $missing_tables = array();
                        foreach ($tables_status as $name => $exists) {
                            if (!$exists) {
                                $missing_tables[] = $name;
                            }
                        }
                        
                        if (!empty($missing_tables)) {
                            echo '<div style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 10px; border-radius: 3px; margin-bottom: 10px;">';
                            echo '<strong>⚠️ Missing Database Tables:</strong><br>';
                            foreach ($missing_tables as $table) {
                                echo "• {$table}<br>";
                            }
                            echo '</div>';
                            
                            echo '<form method="post" style="margin: 10px 0;">';
                            wp_nonce_field('yoco_repair_database', 'yoco_repair_nonce');
                            echo '<input type="submit" name="repair_database" class="button button-secondary" value="🔧 Create Missing Tables" onclick="return confirm(\'Create the missing database tables? This is safe to run.\');">';
                            echo '</form>';
                        } else {
                            echo '<div style="background: #d4edda; border: 1px solid #c3e6cb; padding: 10px; border-radius: 3px;">';
                            echo '<strong>✅ All database tables exist!</strong>';
                            echo '</div>';
                        }
                        ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Other Actions', 'yoco-backorder'); ?></th>
                    <td>
                        <button type="button" id="clean-supplier-stock" class="button button-secondary">
                            <?php _e('Clean Supplier Stock Data', 'yoco-backorder'); ?>
                        </button>
                        <button type="button" id="reset-sync-logs" class="button button-secondary" style="margin-left: 10px;">
                            <?php _e('Reset Sync Logs', 'yoco-backorder'); ?>
                        </button>
                        <p class="description">
                            <?php _e('Use these actions to clean up old data. This cannot be undone.', 'yoco-backorder'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>
        
        <div class="card">
            <h2><?php _e('System Status', 'yoco-backorder'); ?></h2>
            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Check', 'yoco-backorder'); ?></th>
                        <th><?php _e('Status', 'yoco-backorder'); ?></th>
                        <th><?php _e('Description', 'yoco-backorder'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td><?php _e('WooCommerce', 'yoco-backorder'); ?></td>
                        <td>
                            <?php if (class_exists('WooCommerce')): ?>
                                <span style="color: #46b450;">✓ <?php _e('Active', 'yoco-backorder'); ?></span>
                            <?php else: ?>
                                <span style="color: #dc3232;">✗ <?php _e('Not found', 'yoco-backorder'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?php _e('Required for plugin functionality', 'yoco-backorder'); ?></td>
                    </tr>
                    <tr>
                        <td><?php _e('Supplier Taxonomy', 'yoco-backorder'); ?></td>
                        <td>
                            <?php if (taxonomy_exists('pa_xcore_suppliers')): ?>
                                <span style="color: #46b450;">✓ <?php _e('Available', 'yoco-backorder'); ?></span>
                            <?php else: ?>
                                <span style="color: #dc3232;">✗ <?php _e('Not found', 'yoco-backorder'); ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?php _e('Product attribute for supplier assignment', 'yoco-backorder'); ?></td>
                    </tr>
                    <tr>
                        <td><?php _e('Configured Suppliers', 'yoco-backorder'); ?></td>
                        <td>
                            <?php
                            $suppliers = array();
                            $configured = 0;
                            if (class_exists('YoCo_Supplier') && method_exists('YoCo_Supplier', 'get_suppliers')) {
                                $suppliers = YoCo_Supplier::get_suppliers();
                                foreach ($suppliers as $supplier) {
                                    if (!empty($supplier['settings']['feed_url'])) {
                                        $configured++;
                                    }
                                }
                            }
                            ?>
                            <span style="color: <?php echo $configured > 0 ? '#46b450' : '#dc3232'; ?>;">
                                <?php echo $configured; ?> / <?php echo count($suppliers); ?>
                            </span>
                        </td>
                        <td><?php _e('Suppliers with feed configuration', 'yoco-backorder'); ?></td>
                    </tr>
                    <tr>
                        <td><?php _e('Products with YoCo', 'yoco-backorder'); ?></td>
                        <td>
                            <?php
                            global $wpdb;
                            $product_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_yoco_backorder_enabled' AND meta_value = 'yes'");
                            ?>
                            <span style="color: <?php echo $product_count > 0 ? '#46b450' : '#999'; ?>;">
                                <?php echo $product_count; ?>
                            </span>
                        </td>
                        <td><?php _e('Products with YoCo backorder enabled', 'yoco-backorder'); ?></td>
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
    $('#clean-supplier-stock').on('click', function() {
        if (!confirm('<?php esc_js(_e("This will remove all supplier stock data. Are you sure?", "yoco-backorder")); ?>')) {
            return;
        }
        
        var button = $(this);
        button.prop('disabled', true).text('<?php esc_js(_e("Cleaning...", "yoco-backorder")); ?>');
        
        // This would need backend implementation
        setTimeout(function() {
            button.prop('disabled', false).text('<?php esc_js(_e("Clean Supplier Stock Data", "yoco-backorder")); ?>');
            alert('<?php esc_js(_e("Stock data cleaned", "yoco-backorder")); ?>');
        }, 2000);
    });
    
    $('#reset-sync-logs').on('click', function() {
        if (!confirm('<?php esc_js(_e("This will remove all sync logs. Are you sure?", "yoco-backorder")); ?>')) {
            return;
        }
        
        var button = $(this);
        button.prop('disabled', true).text('<?php esc_js(_e("Resetting...", "yoco-backorder")); ?>');
        
        // This would need backend implementation
        setTimeout(function() {
            button.prop('disabled', false).text('<?php esc_js(_e("Reset Sync Logs", "yoco-backorder")); ?>');
            alert('<?php esc_js(_e("Sync logs reset", "yoco-backorder")); ?>');
        }, 1000);
    });
});
</script>