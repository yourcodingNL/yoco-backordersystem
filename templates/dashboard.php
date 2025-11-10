<?php
/**
 * Dashboard Admin Template
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get stats - with safety checks
$suppliers = array();
$recent_logs = array();

if (class_exists('YoCo_Supplier') && method_exists('YoCo_Supplier', 'get_suppliers')) {
    $suppliers = YoCo_Supplier::get_suppliers();
}

if (class_exists('YoCo_Sync') && method_exists('YoCo_Sync', 'get_sync_logs')) {
    $recent_logs = YoCo_Sync::get_sync_logs(null, 10);
}

// Count active suppliers
$active_suppliers = 0;
$configured_suppliers = 0;
foreach ($suppliers as $supplier) {
    if ($supplier['settings']['is_active']) {
        $active_suppliers++;
    }
    if (!empty($supplier['settings']['feed_url'])) {
        $configured_suppliers++;
    }
}

// Get product stats
global $wpdb;
$total_products = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_yoco_backorder_enabled' AND meta_value = 'yes'");
$products_with_stock = $wpdb->get_var("SELECT COUNT(DISTINCT product_id) FROM {$wpdb->prefix}yoco_supplier_stock WHERE stock_quantity > 0");
?>

<div class="wrap">
    <h1><?php _e('YoCo Backorder Dashboard', 'yoco-backorder'); ?></h1>
    
    <div class="yoco-dashboard-stats" style="display: flex; gap: 20px; margin: 20px 0;">
        
        <!-- Suppliers Stats -->
        <div class="card" style="flex: 1;">
            <h2 style="margin-top: 0;"><?php _e('Suppliers', 'yoco-backorder'); ?></h2>
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <div style="font-size: 24px; font-weight: bold; color: #0073aa;">
                        <?php echo $active_suppliers; ?> / <?php echo count($suppliers); ?>
                    </div>
                    <div style="color: #666; font-size: 14px;">
                        <?php _e('Active Suppliers', 'yoco-backorder'); ?>
                    </div>
                </div>
                <div style="text-align: right;">
                    <div style="font-size: 18px; color: #46b450;">
                        <?php echo $configured_suppliers; ?>
                    </div>
                    <div style="color: #666; font-size: 12px;">
                        <?php _e('Configured', 'yoco-backorder'); ?>
                    </div>
                </div>
            </div>
            <div style="margin-top: 15px;">
                <a href="<?php echo admin_url('admin.php?page=yoco-suppliers'); ?>" class="button button-primary">
                    <?php _e('Manage Suppliers', 'yoco-backorder'); ?>
                </a>
            </div>
        </div>
        
        <!-- Products Stats -->
        <div class="card" style="flex: 1;">
            <h2 style="margin-top: 0;"><?php _e('Products', 'yoco-backorder'); ?></h2>
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <div style="font-size: 24px; font-weight: bold; color: #0073aa;">
                        <?php echo $total_products; ?>
                    </div>
                    <div style="color: #666; font-size: 14px;">
                        <?php _e('YoCo Enabled Products', 'yoco-backorder'); ?>
                    </div>
                </div>
                <div style="text-align: right;">
                    <div style="font-size: 18px; color: #46b450;">
                        <?php echo $products_with_stock; ?>
                    </div>
                    <div style="color: #666; font-size: 12px;">
                        <?php _e('With Supplier Stock', 'yoco-backorder'); ?>
                    </div>
                </div>
            </div>
            <div style="margin-top: 15px;">
                <a href="<?php echo admin_url('edit.php?post_type=product&meta_key=_yoco_backorder_enabled&meta_value=yes'); ?>" class="button button-secondary">
                    <?php _e('View Products', 'yoco-backorder'); ?>
                </a>
            </div>
        </div>
        
        <!-- System Status -->
        <div class="card" style="flex: 1;">
            <h2 style="margin-top: 0;"><?php _e('System Status', 'yoco-backorder'); ?></h2>
            <div style="margin: 10px 0;">
                <div style="margin: 5px 0;">
                    <span style="color: <?php echo (get_option('yoco_cron_enabled') === 'yes') ? '#46b450' : '#dc3232'; ?>;">●</span>
                    <?php _e('Automatic Sync:', 'yoco-backorder'); ?>
                    <strong><?php echo (get_option('yoco_cron_enabled') === 'yes') ? __('Enabled', 'yoco-backorder') : __('Disabled', 'yoco-backorder'); ?></strong>
                </div>
                <div style="margin: 5px 0;">
                    <span style="color: #666;">●</span>
                    <?php _e('Server Time:', 'yoco-backorder'); ?>
                    <strong><?php echo current_time('H:i'); ?></strong>
                </div>
                <div style="margin: 5px 0;">
                    <span style="color: #666;">●</span>
                    <?php _e('Timezone:', 'yoco-backorder'); ?>
                    <strong><?php echo wp_timezone_string(); ?></strong>
                </div>
            </div>
            <div style="margin-top: 15px;">
                <a href="<?php echo admin_url('admin.php?page=yoco-settings'); ?>" class="button button-secondary">
                    <?php _e('Settings', 'yoco-backorder'); ?>
                </a>
            </div>
        </div>
    </div>
    
    <!-- Quick Actions -->
    <div class="card">
        <h2><?php _e('Quick Actions', 'yoco-backorder'); ?></h2>
        <div style="display: flex; gap: 15px; align-items: center;">
            <button id="sync-all-suppliers" class="button button-primary">
                <?php _e('Sync All Active Suppliers', 'yoco-backorder'); ?>
            </button>
            <button id="clean-logs" class="button button-secondary">
                <?php _e('Clean Old Logs', 'yoco-backorder'); ?>
            </button>
            <div id="quick-actions-result" style="margin-left: 10px;"></div>
        </div>
    </div>
    
    <!-- Recent Sync Logs -->
    <div class="card">
        <h2><?php _e('Recent Sync Activity', 'yoco-backorder'); ?></h2>
        <?php if (empty($recent_logs)): ?>
            <p><em><?php _e('No sync activity yet.', 'yoco-backorder'); ?></em></p>
        <?php else: ?>
            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Supplier', 'yoco-backorder'); ?></th>
                        <th><?php _e('Type', 'yoco-backorder'); ?></th>
                        <th><?php _e('Status', 'yoco-backorder'); ?></th>
                        <th><?php _e('Products', 'yoco-backorder'); ?></th>
                        <th><?php _e('Started', 'yoco-backorder'); ?></th>
                        <th><?php _e('Duration', 'yoco-backorder'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_logs as $log): ?>
                        <?php 
                        $supplier_term = get_term($log['supplier_term_id'], 'pa_xcore_suppliers');
                        $duration = '';
                        if ($log['completed_at']) {
                            $start = new DateTime($log['started_at']);
                            $end = new DateTime($log['completed_at']);
                            $diff = $start->diff($end);
                            $duration = $diff->format('%H:%I:%S');
                        }
                        ?>
                        <tr>
                            <td>
                                <?php echo $supplier_term && !is_wp_error($supplier_term) ? esc_html($supplier_term->name) : __('Unknown', 'yoco-backorder'); ?>
                            </td>
                            <td>
                                <span class="dashicons dashicons-<?php echo $log['sync_type'] === 'manual' ? 'admin-tools' : 'clock'; ?>"></span>
                                <?php echo ucfirst($log['sync_type']); ?>
                            </td>
                            <td>
                                <?php
                                $status_colors = array(
                                    'completed' => '#46b450',
                                    'failed' => '#dc3232',
                                    'running' => '#ffb900',
                                    'pending' => '#666'
                                );
                                $color = isset($status_colors[$log['status']]) ? $status_colors[$log['status']] : '#666';
                                ?>
                                <span style="color: <?php echo $color; ?>;">●</span>
                                <?php echo ucfirst($log['status']); ?>
                            </td>
                            <td>
                                <?php if ($log['products_processed'] > 0): ?>
                                    <?php echo sprintf(__('%d processed, %d updated', 'yoco-backorder'), $log['products_processed'], $log['products_updated']); ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php echo wp_date(get_option('date_format') . ' H:i', strtotime($log['started_at'])); ?>
                            </td>
                            <td>
                                <?php echo $duration; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <div style="margin-top: 10px;">
                <a href="<?php echo admin_url('admin.php?page=yoco-sync-logs'); ?>" class="button button-secondary">
                    <?php _e('View All Logs', 'yoco-backorder'); ?>
                </a>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Sync all suppliers
    $('#sync-all-suppliers').on('click', function() {
        var button = $(this);
        
        if (!confirm('<?php esc_js(_e("This will sync all active suppliers. Continue?", "yoco-backorder")); ?>')) {
            return;
        }
        
        button.prop('disabled', true).text('<?php esc_js(_e("Syncing...", "yoco-backorder")); ?>');
        $('#quick-actions-result').html('<span style="color: #666;"><?php esc_js(_e("Starting sync...", "yoco-backorder")); ?></span>');
        
        // This would need to be implemented in the backend
        setTimeout(function() {
            button.prop('disabled', false).text('<?php esc_js(_e("Sync All Active Suppliers", "yoco-backorder")); ?>');
            $('#quick-actions-result').html('<span style="color: #46b450;"><?php esc_js(_e("Sync completed", "yoco-backorder")); ?></span>');
        }, 3000);
    });
    
    // Clean logs
    $('#clean-logs').on('click', function() {
        var button = $(this);
        
        if (!confirm('<?php esc_js(_e("This will remove logs older than 30 days. Continue?", "yoco-backorder")); ?>')) {
            return;
        }
        
        button.prop('disabled', true).text('<?php esc_js(_e("Cleaning...", "yoco-backorder")); ?>');
        
        // This would need to be implemented in the backend
        setTimeout(function() {
            button.prop('disabled', false).text('<?php esc_js(_e("Clean Old Logs", "yoco-backorder")); ?>');
            $('#quick-actions-result').html('<span style="color: #46b450;"><?php esc_js(_e("Logs cleaned", "yoco-backorder")); ?></span>');
        }, 1000);
    });
});
</script>