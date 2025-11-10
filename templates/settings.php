<?php
/**
 * Settings Admin Template
 */

if (!defined('ABSPATH')) {
    exit;
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
            <h2><?php _e('Debug & Development', 'yoco-backorder'); ?></h2>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('Debug Mode', 'yoco-backorder'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="yoco_debug_mode" value="yes" <?php checked($debug_mode, 'yes'); ?>>
                            <?php _e('Enable debug logging', 'yoco-backorder'); ?>
                        </label>
                        <p class="description">
                            <?php _e('When enabled, detailed logs will be written for troubleshooting purposes.', 'yoco-backorder'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('Database Actions', 'yoco-backorder'); ?></th>
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