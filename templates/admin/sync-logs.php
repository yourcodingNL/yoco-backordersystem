<?php
/**
 * Sync Logs Admin Template
 */

if (!defined('ABSPATH')) {
    exit;
}

// Get logs with pagination
$per_page = 20;
$page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
$offset = ($page - 1) * $per_page;

// Get supplier filter
$supplier_filter = isset($_GET['supplier']) ? intval($_GET['supplier']) : null;

// Get logs - with safety checks
$logs = array();
if (class_exists('YoCo_Sync') && method_exists('YoCo_Sync', 'get_sync_logs')) {
    $logs = YoCo_Sync::get_sync_logs($supplier_filter, $per_page + 1); // Get one extra to check if there are more
}

// Check if we have more logs
$has_more = count($logs) > $per_page;
if ($has_more) {
    array_pop($logs); // Remove the extra log
}

// Get suppliers for filter - with safety check
$suppliers = array();
if (class_exists('YoCo_Supplier') && method_exists('YoCo_Supplier', 'get_suppliers')) {
    $suppliers = YoCo_Supplier::get_suppliers();
}
?>

<div class="wrap">
    <h1><?php _e('YoCo Sync Logs', 'yoco-backorder'); ?></h1>
    
    <!-- Filter Form -->
    <div class="tablenav top">
        <form method="get" style="float: left;">
            <input type="hidden" name="page" value="yoco-sync-logs">
            <select name="supplier">
                <option value=""><?php _e('All Suppliers', 'yoco-backorder'); ?></option>
                <?php foreach ($suppliers as $supplier): ?>
                    <option value="<?php echo $supplier['term_id']; ?>" <?php selected($supplier_filter, $supplier['term_id']); ?>>
                        <?php echo esc_html($supplier['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <input type="submit" class="button" value="<?php esc_attr_e('Filter', 'yoco-backorder'); ?>">
            <?php if ($supplier_filter): ?>
                <a href="<?php echo admin_url('admin.php?page=yoco-sync-logs'); ?>" class="button">
                    <?php _e('Clear Filter', 'yoco-backorder'); ?>
                </a>
            <?php endif; ?>
        </form>
        
        <div class="alignright">
            <button id="refresh-logs" class="button button-secondary">
                <?php _e('Refresh', 'yoco-backorder'); ?>
            </button>
            <button id="clear-old-logs" class="button button-secondary">
                <?php _e('Clear Old Logs', 'yoco-backorder'); ?>
            </button>
        </div>
        <div class="clear"></div>
    </div>
    
    <?php if (empty($logs)): ?>
        <div class="card">
            <p><em><?php _e('No sync logs found.', 'yoco-backorder'); ?></em></p>
            <p><?php _e('Logs will appear here after running supplier synchronizations.', 'yoco-backorder'); ?></p>
        </div>
    <?php else: ?>
        
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th class="column-primary"><?php _e('Supplier', 'yoco-backorder'); ?></th>
                    <th><?php _e('Type', 'yoco-backorder'); ?></th>
                    <th><?php _e('Status', 'yoco-backorder'); ?></th>
                    <th><?php _e('Products', 'yoco-backorder'); ?></th>
                    <th><?php _e('Errors', 'yoco-backorder'); ?></th>
                    <th><?php _e('Started', 'yoco-backorder'); ?></th>
                    <th><?php _e('Duration', 'yoco-backorder'); ?></th>
                    <th><?php _e('Actions', 'yoco-backorder'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                    <?php 
                    $supplier_term = get_term($log['supplier_term_id'], 'pa_xcore_suppliers');
                    $duration = '';
                    if ($log['completed_at']) {
                        $start = new DateTime($log['started_at']);
                        $end = new DateTime($log['completed_at']);
                        $diff = $start->diff($end);
                        if ($diff->h > 0 || $diff->i > 0 || $diff->s > 0) {
                            $duration = $diff->format('%H:%I:%S');
                        }
                    }
                    
                    $status_colors = array(
                        'completed' => '#46b450',
                        'failed' => '#dc3232',
                        'running' => '#ffb900',
                        'pending' => '#666'
                    );
                    $status_color = isset($status_colors[$log['status']]) ? $status_colors[$log['status']] : '#666';
                    ?>
                    <tr>
                        <td class="column-primary">
                            <strong>
                                <?php echo $supplier_term && !is_wp_error($supplier_term) ? esc_html($supplier_term->name) : __('Unknown Supplier', 'yoco-backorder'); ?>
                            </strong>
                            <button type="button" class="toggle-row"><span class="screen-reader-text"><?php _e('Show more details', 'yoco-backorder'); ?></span></button>
                        </td>
                        <td data-colname="<?php esc_attr_e('Type', 'yoco-backorder'); ?>">
                            <span class="dashicons dashicons-<?php echo $log['sync_type'] === 'manual' ? 'admin-tools' : 'clock'; ?>"></span>
                            <?php echo ucfirst($log['sync_type']); ?>
                        </td>
                        <td data-colname="<?php esc_attr_e('Status', 'yoco-backorder'); ?>">
                            <span style="color: <?php echo $status_color; ?>;">●</span>
                            <strong><?php echo ucfirst($log['status']); ?></strong>
                        </td>
                        <td data-colname="<?php esc_attr_e('Products', 'yoco-backorder'); ?>">
                            <?php if ($log['products_processed'] > 0): ?>
                                <div><strong><?php echo sprintf(__('Processed: %d', 'yoco-backorder'), $log['products_processed']); ?></strong></div>
                                <div><?php echo sprintf(__('Updated: %d', 'yoco-backorder'), $log['products_updated']); ?></div>
                                
                                <?php 
                                // Show detailed statistics if available
                                if (!empty($log['sync_statistics'])) {
                                    $stats = json_decode($log['sync_statistics'], true);
                                    if ($stats) {
                                ?>
                                <div style="font-size: 11px; color: #666; margin-top: 3px;">
                                    <div>📊 Total: <?php echo $stats['total_products']; ?> products</div>
                                    <div>✅ Eigen voorraad: <?php echo $stats['own_stock_available']; ?></div>
                                    <div>🔍 Out of stock: <?php echo $stats['out_of_stock']; ?></div>
                                    <div>📝 Matched in feed: <?php echo $stats['matched_in_feed']; ?></div>
                                    <div>📦 Supplier stock: <?php echo $stats['supplier_has_stock']; ?></div>
                                    <div>🔄 Set backorder: <?php echo $stats['set_to_backorder']; ?></div>
                                    <?php if ($stats['not_found_in_feed'] > 0): ?>
                                    <div style="color: #dc3232;">❌ Not in feed: <?php echo $stats['not_found_in_feed']; ?></div>
                                    <?php endif; ?>
                                    <?php if ($stats['no_sku_ean'] > 0): ?>
                                    <div style="color: #dc3232;">⚠️ No SKU/EAN: <?php echo $stats['no_sku_ean']; ?></div>
                                    <?php endif; ?>
                                </div>
                                <?php 
                                    }
                                }
                                ?>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td data-colname="<?php esc_attr_e('Errors', 'yoco-backorder'); ?>">
                            <?php if ($log['errors_count'] > 0): ?>
                                <span style="color: #dc3232;"><?php echo $log['errors_count']; ?></span>
                                <button type="button" class="button button-small view-errors" data-log-id="<?php echo $log['id']; ?>">
                                    <?php _e('View', 'yoco-backorder'); ?>
                                </button>
                            <?php else: ?>
                                <span style="color: #46b450;">0</span>
                            <?php endif; ?>
                        </td>
                        <td data-colname="<?php esc_attr_e('Started', 'yoco-backorder'); ?>">
                            <?php echo wp_date('M j, Y', strtotime($log['started_at'])); ?><br>
                            <small><?php echo wp_date('H:i:s', strtotime($log['started_at'])); ?></small>
                        </td>
                        <td data-colname="<?php esc_attr_e('Duration', 'yoco-backorder'); ?>">
                            <?php if ($duration): ?>
                                <?php echo $duration; ?>
                            <?php elseif ($log['status'] === 'running'): ?>
                                <em><?php _e('Running...', 'yoco-backorder'); ?></em>
                            <?php else: ?>
                                -
                            <?php endif; ?>
                        </td>
                        <td data-colname="<?php esc_attr_e('Actions', 'yoco-backorder'); ?>">
                            <?php if ($supplier_term && !is_wp_error($supplier_term)): ?>
                                <a href="<?php echo admin_url('admin.php?page=yoco-suppliers&supplier=' . $supplier_term->term_id); ?>" class="button button-small">
                                    <?php _e('Configure', 'yoco-backorder'); ?>
                                </a>
                            <?php endif; ?>
                            <?php if ($log['status'] === 'completed' || $log['status'] === 'failed'): ?>
                                <button type="button" class="button button-small retry-sync" data-supplier-id="<?php echo $log['supplier_term_id']; ?>">
                                    <?php _e('Retry', 'yoco-backorder'); ?>
                                </button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    
                    <!-- Error Details Row (hidden by default) -->
                    <?php if ($log['errors_count'] > 0 && !empty($log['error_messages'])): ?>
                        <tr id="errors-<?php echo $log['id']; ?>" class="error-details" style="display: none;">
                            <td colspan="8">
                                <div style="background: #fff; border: 1px solid #c3c4c7; padding: 10px; margin: 5px 0;">
                                    <h4><?php _e('Error Details:', 'yoco-backorder'); ?></h4>
                                    <div style="background: #f6f7f7; padding: 8px; font-family: monospace; font-size: 12px; white-space: pre-wrap;">
                                        <?php
                                        $errors = json_decode($log['error_messages'], true);
                                        if (is_array($errors)) {
                                            foreach ($errors as $error) {
                                                echo esc_html($error) . "\n";
                                            }
                                        } else {
                                            echo esc_html($log['error_messages']);
                                        }
                                        ?>
                                    </div>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                    
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <!-- Pagination -->
        <div class="tablenav bottom">
            <div class="alignleft">
                <?php if ($page > 1): ?>
                    <a href="<?php echo add_query_arg('paged', $page - 1); ?>" class="button">
                        ← <?php _e('Previous', 'yoco-backorder'); ?>
                    </a>
                <?php endif; ?>
                <?php if ($has_more): ?>
                    <a href="<?php echo add_query_arg('paged', $page + 1); ?>" class="button">
                        <?php _e('Next', 'yoco-backorder'); ?> →
                    </a>
                <?php endif; ?>
            </div>
            <div class="alignright">
                <span class="displaying-num">
                    <?php echo sprintf(__('Page %d', 'yoco-backorder'), $page); ?>
                </span>
            </div>
            <div class="clear"></div>
        </div>
        
    <?php endif; ?>
</div>

<script>
jQuery(document).ready(function($) {
    // View error details
    $('.view-errors').on('click', function() {
        var logId = $(this).data('log-id');
        $('#errors-' + logId).toggle();
        $(this).text($(this).text() === '<?php esc_js(_e("View", "yoco-backorder")); ?>' ? '<?php esc_js(_e("Hide", "yoco-backorder")); ?>' : '<?php esc_js(_e("View", "yoco-backorder")); ?>');
    });
    
    // Retry sync
    $('.retry-sync').on('click', function() {
        var button = $(this);
        var supplierId = button.data('supplier-id');
        
        if (!confirm('<?php esc_js(_e("Start new sync for this supplier?", "yoco-backorder")); ?>')) {
            return;
        }
        
        button.prop('disabled', true).text('<?php esc_js(_e("Starting...", "yoco-backorder")); ?>');
        
        $.ajax({
            url: yoco_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'yoco_sync_supplier',
                supplier_id: supplierId,
                nonce: yoco_admin.nonce
            },
            success: function(response) {
                if (response.success) {
                    alert(response.message);
                    location.reload();
                } else {
                    alert('<?php esc_js(_e("Sync failed:", "yoco-backorder")); ?> ' + response.message);
                }
            },
            error: function() {
                alert('<?php esc_js(_e("Error starting sync", "yoco-backorder")); ?>');
            },
            complete: function() {
                button.prop('disabled', false).text('<?php esc_js(_e("Retry", "yoco-backorder")); ?>');
            }
        });
    });
    
    // Refresh logs
    $('#refresh-logs').on('click', function() {
        location.reload();
    });
    
    // Clear old logs
    $('#clear-old-logs').on('click', function() {
        if (!confirm('<?php esc_js(_e("This will remove ALL sync logs. Continue?", "yoco-backorder")); ?>')) {
            return;
        }
        
        var button = $(this);
        button.prop('disabled', true).text('<?php esc_js(_e("Clearing...", "yoco-backorder")); ?>');
        
        $.ajax({
            url: yoco_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'yoco_clean_old_logs',
                nonce: yoco_admin.nonce
            },
            success: function(response) {
                if (response.success) {
                    alert(response.message);
                    location.reload();
                } else {
                    alert('<?php esc_js(_e("Error:", "yoco-backorder")); ?> ' + response.message);
                }
            },
            error: function() {
                alert('<?php esc_js(_e("AJAX error occurred", "yoco-backorder")); ?>');
            },
            complete: function() {
                button.prop('disabled', false).text('<?php esc_js(_e("Clear Old Logs", "yoco-backorder")); ?>');
            }
        });
    });
});
</script>