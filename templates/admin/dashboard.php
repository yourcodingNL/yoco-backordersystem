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
    // CHECK FOR BOTH URL AND FTP CONFIGURATION
    if (!empty($supplier['settings']['feed_url'])) {
        $configured_suppliers++; // URL mode
    } elseif ($supplier['settings']['connection_type'] === 'ftp' && 
             !empty($supplier['settings']['ftp_host']) && 
             !empty($supplier['settings']['ftp_user']) && 
             !empty($supplier['settings']['ftp_path'])) {
        $configured_suppliers++; // FTP mode
    }
}

// Get product stats - with debug info
global $wpdb;

// Debug: Show what we're looking for
echo '<div style="background: #fff3cd; padding: 10px; margin: 10px 0; border: 1px solid #ffeaa7;">';
echo '<h4>YoCo Debug Info:</h4>';

// Check if our database tables exist
$tables_exist = array();
$schema = array(
    'supplier_settings' => $wpdb->prefix . 'yoco_supplier_settings',
    'supplier_stock' => $wpdb->prefix . 'yoco_supplier_stock', 
    'sync_logs' => $wpdb->prefix . 'yoco_sync_logs'
);

foreach ($schema as $name => $table) {
    $exists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'");
    $tables_exist[$name] = !empty($exists);
    echo "Table {$name}: " . ($tables_exist[$name] ? '✅ EXISTS' : '❌ MISSING') . "<br>";
}

// Debug the product count query
$debug_query = "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_yoco_backorder_enabled' AND meta_value = 'yes'";
echo "<br><strong>Product Query:</strong><br><code>{$debug_query}</code><br>";

$total_products = $wpdb->get_var($debug_query);
echo "<strong>Result:</strong> {$total_products} products found<br>";

// Show some actual meta values to debug
$sample_meta = $wpdb->get_results("SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_yoco_backorder_enabled' LIMIT 5", ARRAY_A);
echo "<br><strong>Sample meta data:</strong><br>";
foreach ($sample_meta as $meta) {
    echo "Post ID {$meta['post_id']}: {$meta['meta_key']} = '{$meta['meta_value']}'<br>";
}

$products_with_stock = $wpdb->get_var("SELECT COUNT(DISTINCT product_id) FROM {$wpdb->prefix}yoco_supplier_stock WHERE stock_quantity > 0");

echo "</div>";
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
                <div style="margin: 5px 0;">
                    <span style="color: #666;">●</span>
                    <?php 
                    // Debug: Count active suppliers
                    $debug_suppliers = 0;
                    if (class_exists('YoCo_Supplier') && method_exists('YoCo_Supplier', 'get_suppliers')) {
                        $all_suppliers = YoCo_Supplier::get_suppliers();
                        foreach ($all_suppliers as $supplier) {
                            if (!empty($supplier['settings']['feed_url']) && $supplier['settings']['is_active']) {
                                $debug_suppliers++;
                            }
                        }
                    }
                    ?>
                    <?php _e('Active Suppliers:', 'yoco-backorder'); ?>
                    <strong style="color: <?php echo $debug_suppliers > 0 ? '#46b450' : '#dc3232'; ?>;"><?php echo $debug_suppliers; ?></strong>
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
        
        console.log('Sync all button clicked');
        
        if (!confirm('<?php esc_js(_e("This will sync all active suppliers. Continue?", "yoco-backorder")); ?>')) {
            return;
        }
        
        button.prop('disabled', true).text('<?php esc_js(_e("Syncing...", "yoco-backorder")); ?>');
        
        // Create progress area
        var progressHtml = '<div id="sync-progress" style="margin-top: 15px; padding: 15px; background: #f9f9f9; border-radius: 5px; max-height: 300px; overflow-y: auto; font-family: monospace; font-size: 12px; line-height: 1.4;"></div>';
        $('#quick-actions-result').html(progressHtml);
        
        function addProgressLine(text) {
            var timestamp = new Date().toLocaleTimeString('nl-NL', {
                timeZone: 'Europe/Amsterdam',
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            });
            $('#sync-progress').append('<div>[' + timestamp + '] ' + text + '</div>');
            $('#sync-progress').scrollTop($('#sync-progress')[0].scrollHeight);
        }
        
        addProgressLine('🚀 Starting sync of all active suppliers...');
        
        // Debug info
        console.log('AJAX URL:', yoco_admin.ajax_url);
        console.log('Nonce:', yoco_admin.nonce);
        
        $.ajax({
            url: yoco_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'yoco_sync_all_suppliers',
                nonce: yoco_admin.nonce
            },
            timeout: 300000, // 5 minutes timeout for big syncs
            success: function(response) {
                console.log('AJAX Success:', response);
                if (response.success) {
                    // Show detailed progress
                    if (response.progress) {
                        response.progress.forEach(function(line) {
                            if (line.trim() !== '') {
                                addProgressLine(line);
                            }
                        });
                    }
                    
                    addProgressLine('');
                    addProgressLine('✨ ' + response.message);
                    
                    // Show final result
                    setTimeout(function() {
                        var resultHtml = '<div style="background: #d4edda; border: 1px solid #c3e6cb; padding: 10px; border-radius: 5px; margin-top: 10px;">';
                        resultHtml += '<strong>✅ Sync Completed!</strong><br>';
                        resultHtml += response.message;
                        resultHtml += '</div>';
                        $('#quick-actions-result').append(resultHtml);
                        
                        // Auto-refresh after 5 seconds
                        setTimeout(function() {
                            location.reload();
                        }, 5000);
                    }, 2000);
                } else {
                    addProgressLine('❌ Error: ' + response.message);
                    $('#quick-actions-result').append('<div style="color: #dc3232; margin-top: 10px;">Error: ' + response.message + '</div>');
                }
            },
            error: function(xhr, status, error) {
                console.log('AJAX Error:', xhr, status, error);
                addProgressLine('💥 AJAX error: ' + error);
                $('#quick-actions-result').append('<div style="color: #dc3232; margin-top: 10px;">AJAX error: ' + error + '</div>');
            },
            complete: function() {
                button.prop('disabled', false).text('<?php esc_js(_e("Sync All Active Suppliers", "yoco-backorder")); ?>');
            }
        });
    });
    
    // Clean logs
    $('#clean-logs').on('click', function() {
        var button = $(this);
        
        if (!confirm('<?php esc_js(_e("This will remove ALL sync logs. Continue?", "yoco-backorder")); ?>')) {
            return;
        }
        
        button.prop('disabled', true).text('<?php esc_js(_e("Cleaning...", "yoco-backorder")); ?>');
        
        $.ajax({
            url: yoco_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'yoco_clean_old_logs',
                nonce: yoco_admin.nonce
            },
            success: function(response) {
                if (response.success) {
                    $('#quick-actions-result').html('<span style="color: #46b450;">' + response.message + '</span>');
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    $('#quick-actions-result').html('<span style="color: #dc3232;">Error: ' + response.message + '</span>');
                }
            },
            error: function() {
                $('#quick-actions-result').html('<span style="color: #dc3232;">AJAX error occurred</span>');
            },
            complete: function() {
                button.prop('disabled', false).text('<?php esc_js(_e("Clean Old Logs", "yoco-backorder")); ?>');
            }
        });
    });
});
</script>