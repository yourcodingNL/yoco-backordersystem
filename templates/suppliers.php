<?php
/**
 * Suppliers Admin Template
 */

if (!defined('ABSPATH')) {
    exit;
}

// Handle form submission
if (isset($_POST['save_supplier']) && wp_verify_nonce($_POST['yoco_nonce'], 'yoco_save_supplier')) {
    $supplier_id = intval($_POST['supplier_id']);
    $settings = array(
        'feed_url' => sanitize_text_field($_POST['feed_url']),
        'update_frequency' => intval($_POST['update_frequency']),
        'update_times' => array_map('sanitize_text_field', $_POST['update_times']),
        'default_delivery_time' => sanitize_text_field($_POST['default_delivery_time']),
        'csv_delimiter' => sanitize_text_field($_POST['csv_delimiter']),
        'csv_has_header' => isset($_POST['csv_has_header']) ? 1 : 0,
        'sku_column' => sanitize_text_field($_POST['sku_column']),
        'stock_column' => sanitize_text_field($_POST['stock_column']),
        'mapping_config' => array(), // Will be handled by JS
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
    );
    
    if (YoCo_Supplier::save_supplier_settings($supplier_id, $settings)) {
        echo '<div class="notice notice-success"><p>' . __('Supplier settings saved successfully.', 'yoco-backorder') . '</p></div>';
    } else {
        echo '<div class="notice notice-error"><p>' . __('Failed to save supplier settings.', 'yoco-backorder') . '</p></div>';
    }
}

// Get suppliers - with safety check
$suppliers = array();
if (class_exists('YoCo_Supplier') && method_exists('YoCo_Supplier', 'get_suppliers')) {
    $suppliers = YoCo_Supplier::get_suppliers();
}
$current_supplier_id = isset($_GET['supplier']) ? intval($_GET['supplier']) : null;
$current_supplier = null;

if ($current_supplier_id) {
    foreach ($suppliers as $supplier) {
        if ($supplier['term_id'] == $current_supplier_id) {
            $current_supplier = $supplier;
            break;
        }
    }
}
?>

<div class="wrap">
    <h1><?php _e('YoCo Suppliers', 'yoco-backorder'); ?></h1>
    
    <div class="yoco-suppliers-wrapper" style="display: flex; gap: 20px;">
        
        <!-- Suppliers List -->
        <div class="yoco-suppliers-list" style="flex: 0 0 300px;">
            <div class="card">
                <h2><?php _e('Suppliers', 'yoco-backorder'); ?></h2>
                
                <?php if (empty($suppliers)): ?>
                    <p><?php _e('No suppliers found. Please configure suppliers in the product attributes first.', 'yoco-backorder'); ?></p>
                    <a href="<?php echo admin_url('edit-tags.php?taxonomy=pa_xcore_suppliers&post_type=product'); ?>" class="button button-primary">
                        <?php _e('Manage Suppliers', 'yoco-backorder'); ?>
                    </a>
                <?php else: ?>
                    <div class="yoco-suppliers-items">
                        <?php foreach ($suppliers as $supplier): ?>
                            <?php 
                            $is_active = $current_supplier_id == $supplier['term_id'];
                            $has_settings = !empty($supplier['settings']['feed_url']);
                            ?>
                            <div class="supplier-item <?php echo $is_active ? 'active' : ''; ?>" style="padding: 10px; margin: 5px 0; border: 1px solid #ddd; border-radius: 3px; <?php echo $is_active ? 'background: #f0f8ff; border-color: #0073aa;' : ''; ?>">
                                <div style="display: flex; justify-content: space-between; align-items: center;">
                                    <div>
                                        <strong><?php echo esc_html($supplier['name']); ?></strong>
                                        <div style="font-size: 12px; color: #666;">
                                            <?php if ($has_settings): ?>
                                                <span style="color: #46b450;">●</span> <?php _e('Configured', 'yoco-backorder'); ?>
                                            <?php else: ?>
                                                <span style="color: #dc3232;">●</span> <?php _e('Not configured', 'yoco-backorder'); ?>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div>
                                        <a href="?page=yoco-suppliers&supplier=<?php echo $supplier['term_id']; ?>" class="button button-small">
                                            <?php _e('Configure', 'yoco-backorder'); ?>
                                        </a>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Supplier Configuration -->
        <div class="yoco-supplier-config" style="flex: 1;">
            <?php if ($current_supplier): ?>
                <div class="card">
                    <h2><?php echo sprintf(__('Configure: %s', 'yoco-backorder'), esc_html($current_supplier['name'])); ?></h2>
                    
                    <form method="post" id="yoco-supplier-form">
                        <?php wp_nonce_field('yoco_save_supplier', 'yoco_nonce'); ?>
                        <input type="hidden" name="supplier_id" value="<?php echo $current_supplier['term_id']; ?>">
                        
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="feed_url"><?php _e('Feed URL', 'yoco-backorder'); ?></label>
                                </th>
                                <td>
                                    <input type="url" id="feed_url" name="feed_url" value="<?php echo esc_attr($current_supplier['settings']['feed_url']); ?>" class="regular-text" required>
                                    <button type="button" id="test-feed" class="button button-secondary" style="margin-left: 10px;">
                                        <?php _e('Test Feed', 'yoco-backorder'); ?>
                                    </button>
                                    <div id="feed-test-result" style="margin-top: 10px;"></div>
                                </td>
                            </tr>
                            
                            <tr>
                                <th scope="row">
                                    <label for="csv_delimiter"><?php _e('CSV Delimiter', 'yoco-backorder'); ?></label>
                                </th>
                                <td>
                                    <select id="csv_delimiter" name="csv_delimiter">
                                        <option value="," <?php selected($current_supplier['settings']['csv_delimiter'], ','); ?>><?php _e('Comma (,)', 'yoco-backorder'); ?></option>
                                        <option value=";" <?php selected($current_supplier['settings']['csv_delimiter'], ';'); ?>><?php _e('Semicolon (;)', 'yoco-backorder'); ?></option>
                                        <option value="\t" <?php selected($current_supplier['settings']['csv_delimiter'], '\t'); ?>><?php _e('Tab', 'yoco-backorder'); ?></option>
                                        <option value="|" <?php selected($current_supplier['settings']['csv_delimiter'], '|'); ?>><?php _e('Pipe (|)', 'yoco-backorder'); ?></option>
                                    </select>
                                </td>
                            </tr>
                            
                            <tr>
                                <th scope="row">
                                    <?php _e('CSV Mapping', 'yoco-backorder'); ?>
                                </th>
                                <td>
                                    <div id="csv-mapping" style="margin: 10px 0;">
                                        <p><em><?php _e('Test the feed first to see available columns', 'yoco-backorder'); ?></em></p>
                                        
                                        <div style="margin: 10px 0;">
                                            <label for="sku_column"><?php _e('SKU Column:', 'yoco-backorder'); ?></label>
                                            <input type="text" id="sku_column" name="sku_column" value="<?php echo esc_attr($current_supplier['settings']['sku_column']); ?>" class="regular-text">
                                        </div>
                                        
                                        <div style="margin: 10px 0;">
                                            <label for="stock_column"><?php _e('Stock Column:', 'yoco-backorder'); ?></label>
                                            <input type="text" id="stock_column" name="stock_column" value="<?php echo esc_attr($current_supplier['settings']['stock_column']); ?>" class="regular-text">
                                        </div>
                                    </div>
                                </td>
                            </tr>
                            
                            <tr>
                                <th scope="row">
                                    <label for="default_delivery_time"><?php _e('Default Delivery Time', 'yoco-backorder'); ?></label>
                                </th>
                                <td>
                                    <input type="text" id="default_delivery_time" name="default_delivery_time" value="<?php echo esc_attr($current_supplier['settings']['default_delivery_time']); ?>" class="regular-text">
                                    <p class="description"><?php _e('Text to show when product is available from supplier (e.g., "3 tot 5 werkdagen")', 'yoco-backorder'); ?></p>
                                </td>
                            </tr>
                            
                            <tr>
                                <th scope="row">
                                    <label for="update_frequency"><?php _e('Update Frequency', 'yoco-backorder'); ?></label>
                                </th>
                                <td>
                                    <select id="update_frequency" name="update_frequency">
                                        <?php for ($i = 1; $i <= 24; $i++): ?>
                                            <option value="<?php echo $i; ?>" <?php selected($current_supplier['settings']['update_frequency'], $i); ?>>
                                                <?php echo sprintf(_n('%d time per day', '%d times per day', $i, 'yoco-backorder'), $i); ?>
                                            </option>
                                        <?php endfor; ?>
                                    </select>
                                </td>
                            </tr>
                            
                            <tr>
                                <th scope="row">
                                    <?php _e('Update Times', 'yoco-backorder'); ?>
                                </th>
                                <td>
                                    <div id="update-times">
                                        <?php 
                                        $times = $current_supplier['settings']['update_times'];
                                        if (empty($times)) $times = array('09:00');
                                        foreach ($times as $index => $time): 
                                        ?>
                                            <div class="time-input" style="margin: 5px 0;">
                                                <input type="time" name="update_times[]" value="<?php echo esc_attr($time); ?>">
                                                <button type="button" class="button button-small remove-time" style="margin-left: 5px;"><?php _e('Remove', 'yoco-backorder'); ?></button>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                    <button type="button" id="add-time" class="button button-secondary"><?php _e('Add Time', 'yoco-backorder'); ?></button>
                                    <p class="description">
                                        <?php _e('Current server time:', 'yoco-backorder'); ?> <strong><?php echo current_time('H:i'); ?></strong>
                                        (<?php echo wp_timezone_string(); ?>)
                                    </p>
                                </td>
                            </tr>
                            
                            <tr>
                                <th scope="row">
                                    <?php _e('Settings', 'yoco-backorder'); ?>
                                </th>
                                <td>
                                    <label>
                                        <input type="checkbox" name="csv_has_header" value="1" <?php checked($current_supplier['settings']['csv_has_header'], 1); ?>>
                                        <?php _e('CSV has header row', 'yoco-backorder'); ?>
                                    </label>
                                    <br><br>
                                    <label>
                                        <input type="checkbox" name="is_active" value="1" <?php checked($current_supplier['settings']['is_active'], 1); ?>>
                                        <?php _e('Supplier is active', 'yoco-backorder'); ?>
                                    </label>
                                </td>
                            </tr>
                        </table>
                        
                        <p class="submit">
                            <input type="submit" name="save_supplier" class="button button-primary" value="<?php esc_attr_e('Save Settings', 'yoco-backorder'); ?>">
                            <button type="button" id="sync-supplier" class="button button-secondary" style="margin-left: 10px;">
                                <?php _e('Manual Sync', 'yoco-backorder'); ?>
                            </button>
                        </p>
                    </form>
                </div>
            <?php else: ?>
                <div class="card">
                    <h2><?php _e('Select Supplier', 'yoco-backorder'); ?></h2>
                    <p><?php _e('Select a supplier from the list to configure its settings.', 'yoco-backorder'); ?></p>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Test feed functionality
    $('#test-feed').on('click', function() {
        var button = $(this);
        var feedUrl = $('#feed_url').val();
        var delimiter = $('#csv_delimiter').val();
        
        if (!feedUrl) {
            alert('<?php esc_js(_e("Please enter a feed URL first", "yoco-backorder")); ?>');
            return;
        }
        
        button.prop('disabled', true).text('<?php esc_js(_e("Testing...", "yoco-backorder")); ?>');
        
        $.ajax({
            url: yoco_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'yoco_test_supplier_feed',
                feed_url: feedUrl,
                delimiter: delimiter,
                nonce: yoco_admin.nonce
            },
            success: function(response) {
                if (response.success) {
                    var html = '<div style="background: #d4edda; border: 1px solid #c3e6cb; padding: 10px; border-radius: 3px;">';
                    html += '<strong><?php esc_js(_e("Feed Test Successful", "yoco-backorder")); ?></strong><br>';
                    html += '<?php esc_js(_e("Total rows:", "yoco-backorder")); ?> ' + response.data.total_rows + '<br>';
                    html += '<?php esc_js(_e("Available columns:", "yoco-backorder")); ?> ' + response.data.columns.join(', ');
                    html += '</div>';
                    $('#feed-test-result').html(html);
                    
                    // Update column dropdowns
                    var columns = response.data.columns;
                    updateColumnSelects(columns);
                    
                } else {
                    var html = '<div style="background: #f8d7da; border: 1px solid #f5c6cb; padding: 10px; border-radius: 3px;">';
                    html += '<strong><?php esc_js(_e("Feed Test Failed", "yoco-backorder")); ?></strong><br>';
                    html += response.message;
                    html += '</div>';
                    $('#feed-test-result').html(html);
                }
            },
            error: function() {
                var html = '<div style="background: #f8d7da; border: 1px solid #f5c6cb; padding: 10px; border-radius: 3px;">';
                html += '<?php esc_js(_e("Error testing feed", "yoco-backorder")); ?>';
                html += '</div>';
                $('#feed-test-result').html(html);
            },
            complete: function() {
                button.prop('disabled', false).text('<?php esc_js(_e("Test Feed", "yoco-backorder")); ?>');
            }
        });
    });
    
    // Update column selects
    function updateColumnSelects(columns) {
        var skuSelect = $('#sku_column');
        var stockSelect = $('#stock_column');
        
        // Update SKU column suggestions
        skuSelect.attr('list', 'sku-datalist');
        if (!$('#sku-datalist').length) {
            $('body').append('<datalist id="sku-datalist"></datalist>');
        }
        $('#sku-datalist').empty();
        columns.forEach(function(column) {
            $('#sku-datalist').append('<option value="' + column + '">');
        });
        
        // Update stock column suggestions  
        stockSelect.attr('list', 'stock-datalist');
        if (!$('#stock-datalist').length) {
            $('body').append('<datalist id="stock-datalist"></datalist>');
        }
        $('#stock-datalist').empty();
        columns.forEach(function(column) {
            $('#stock-datalist').append('<option value="' + column + '">');
        });
    }
    
    // Add/remove time inputs
    $('#add-time').on('click', function() {
        var timeHtml = '<div class="time-input" style="margin: 5px 0;">';
        timeHtml += '<input type="time" name="update_times[]" value="09:00">';
        timeHtml += '<button type="button" class="button button-small remove-time" style="margin-left: 5px;"><?php esc_js(_e("Remove", "yoco-backorder")); ?></button>';
        timeHtml += '</div>';
        $('#update-times').append(timeHtml);
    });
    
    $(document).on('click', '.remove-time', function() {
        $(this).closest('.time-input').remove();
    });
    
    // Manual sync
    $('#sync-supplier').on('click', function() {
        var button = $(this);
        var supplierId = $('input[name="supplier_id"]').val();
        
        if (!confirm('<?php esc_js(_e("Are you sure you want to start manual sync?", "yoco-backorder")); ?>')) {
            return;
        }
        
        button.prop('disabled', true).text('<?php esc_js(_e("Syncing...", "yoco-backorder")); ?>');
        
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
                } else {
                    alert('<?php esc_js(_e("Sync failed:", "yoco-backorder")); ?> ' + response.message);
                }
            },
            error: function() {
                alert('<?php esc_js(_e("Error during sync", "yoco-backorder")); ?>');
            },
            complete: function() {
                button.prop('disabled', false).text('<?php esc_js(_e("Manual Sync", "yoco-backorder")); ?>');
            }
        });
    });
});
</script>