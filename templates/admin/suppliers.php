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
        'connection_type' => sanitize_text_field($_POST['connection_type']),
        'feed_url' => sanitize_text_field($_POST['feed_url']),
        'ftp_host' => sanitize_text_field($_POST['ftp_host'] ?? ''),
        'ftp_port' => intval($_POST['ftp_port'] ?? 21),
        'ftp_user' => sanitize_text_field($_POST['ftp_user'] ?? ''),
        'ftp_pass' => sanitize_text_field($_POST['ftp_pass'] ?? ''),
        'ftp_path' => sanitize_text_field($_POST['ftp_path'] ?? ''),
        'ftp_passive' => isset($_POST['ftp_passive']) ? 1 : 0,
        'update_frequency' => intval($_POST['update_frequency']),
        'update_times' => array_map('sanitize_text_field', $_POST['update_times']),
        'default_delivery_time' => sanitize_text_field($_POST['default_delivery_time']),
        'csv_delimiter' => sanitize_text_field($_POST['csv_delimiter']),
        'csv_has_header' => isset($_POST['csv_has_header']) ? 1 : 0,
        'sku_column' => sanitize_text_field($_POST['sku_column']),
        'stock_column' => sanitize_text_field($_POST['stock_column']),
        'mapping_config' => array(),
        'is_active' => isset($_POST['is_active']) ? 1 : 0,
    );
    
    if (YoCo_Supplier::save_supplier_settings($supplier_id, $settings)) {
        echo '<div class="notice notice-success"><p>' . __('Supplier settings saved successfully.', 'yoco-backorder') . '</p></div>';
    } else {
        echo '<div class="notice notice-error"><p>' . __('Failed to save supplier settings.', 'yoco-backorder') . '</p></div>';
    }
}

// EMERGENCY DATABASE FIX
if (isset($_POST['emergency_db_fix']) && wp_verify_nonce($_POST['yoco_emergency_nonce'], 'yoco_emergency_fix')) {
    global $wpdb;
    $supplier_table = $wpdb->prefix . 'yoco_supplier_settings';
    
    $ftp_columns = array(
        'connection_type' => "ALTER TABLE {$supplier_table} ADD COLUMN connection_type varchar(10) DEFAULT 'url' AFTER supplier_term_id",
        'ftp_host' => "ALTER TABLE {$supplier_table} ADD COLUMN ftp_host varchar(255) DEFAULT '' AFTER feed_url",
        'ftp_port' => "ALTER TABLE {$supplier_table} ADD COLUMN ftp_port int(5) DEFAULT 21 AFTER ftp_host",
        'ftp_user' => "ALTER TABLE {$supplier_table} ADD COLUMN ftp_user varchar(255) DEFAULT '' AFTER ftp_port",
        'ftp_pass' => "ALTER TABLE {$supplier_table} ADD COLUMN ftp_pass varchar(255) DEFAULT '' AFTER ftp_user",
        'ftp_path' => "ALTER TABLE {$supplier_table} ADD COLUMN ftp_path varchar(255) DEFAULT '' AFTER ftp_pass",
        'ftp_passive' => "ALTER TABLE {$supplier_table} ADD COLUMN ftp_passive tinyint(1) DEFAULT 1 AFTER ftp_path"
    );
    
    $added = array();
    $errors = array();
    
    foreach ($ftp_columns as $column_name => $sql) {
        $column_exists = $wpdb->get_results("SHOW COLUMNS FROM {$supplier_table} LIKE '{$column_name}'");
        if (empty($column_exists)) {
            $result = $wpdb->query($sql);
            if ($result !== false) {
                $added[] = $column_name;
            } else {
                $errors[] = $column_name . ': ' . $wpdb->last_error;
            }
        }
    }
    
    if (empty($errors)) {
        echo '<div class="notice notice-success"><p><strong>✅ DATABASE FIXED!</strong> Added FTP columns: ' . implode(', ', $added) . '. You can now save supplier settings!</p></div>';
    } else {
        echo '<div class="notice notice-error"><p><strong>❌ Database fix failed:</strong><br>' . implode('<br>', $errors) . '</p></div>';
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
    
    <!-- EMERGENCY DATABASE FIX -->
    <?php
    global $wpdb;
    $supplier_table = $wpdb->prefix . 'yoco_supplier_settings';
    $connection_type_exists = $wpdb->get_results("SHOW COLUMNS FROM {$supplier_table} LIKE 'connection_type'");
    
    if (empty($connection_type_exists)) {
        echo '<div style="background: #dc3545; color: white; padding: 15px; border-radius: 5px; margin: 20px 0;">';
        echo '<strong>🚨 DATABASE ERROR DETECTED!</strong><br>';
        echo 'FTP columns are missing from database. Click button below to fix:';
        echo '<form method="post" style="margin: 10px 0;">';
        wp_nonce_field('yoco_emergency_fix', 'yoco_emergency_nonce');
        echo '<input type="submit" name="emergency_db_fix" class="button button-primary" value="🔧 FIX DATABASE NOW" style="background: #28a745; border-color: #28a745; margin-top: 10px;">';
        echo '</form>';
        echo '</div>';
    }
    ?>
    
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
                            
                            // CHECK FOR BOTH URL AND FTP CONFIGURATION
                            $has_settings = false;
                            if (!empty($supplier['settings']['feed_url'])) {
                                $has_settings = true; // URL mode
                            } elseif ($supplier['settings']['connection_type'] === 'ftp' && 
                                     !empty($supplier['settings']['ftp_host']) && 
                                     !empty($supplier['settings']['ftp_user']) && 
                                     !empty($supplier['settings']['ftp_path'])) {
                                $has_settings = true; // FTP mode
                            }
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
                                    <label for="connection_type"><?php _e('Connection Type', 'yoco-backorder'); ?></label>
                                </th>
                                <td>
                                    <select id="connection_type" name="connection_type">
                                        <option value="url" <?php selected($current_supplier['settings']['connection_type'] ?? 'url', 'url'); ?>><?php _e('HTTP/HTTPS URL', 'yoco-backorder'); ?></option>
                                        <option value="ftp" <?php selected($current_supplier['settings']['connection_type'] ?? 'url', 'ftp'); ?>><?php _e('FTP Server', 'yoco-backorder'); ?></option>
                                    </select>
                                </td>
                            </tr>
                            
                            <tr id="feed_url_row">
                                <th scope="row">
                                    <label for="feed_url"><?php _e('Feed URL', 'yoco-backorder'); ?></label>
                                </th>
                                <td>
                                    <input type="url" id="feed_url" name="feed_url" value="<?php echo esc_attr($current_supplier['settings']['feed_url']); ?>" class="regular-text">
                                    <button type="button" id="test-feed" class="button button-secondary" style="margin-left: 10px;">
                                        <?php _e('Test Feed', 'yoco-backorder'); ?>
                                    </button>
                                    <div id="feed-test-result" style="margin-top: 10px;"></div>
                                </td>
                            </tr>
                            
                            <tr id="ftp_settings_row" style="display: none;">
                                <th scope="row">
                                    <?php _e('FTP Settings', 'yoco-backorder'); ?>
                                </th>
                                <td>
                                    <div style="margin: 10px 0;">
                                        <label for="ftp_host"><?php _e('FTP Host:', 'yoco-backorder'); ?></label><br>
                                        <input type="text" id="ftp_host" name="ftp_host" value="<?php echo esc_attr($current_supplier['settings']['ftp_host'] ?? ''); ?>" class="regular-text" placeholder="ftp.yourcoding.nl">
                                        <p class="description"><?php _e('Just hostname, no ftp:// prefix', 'yoco-backorder'); ?></p>
                                    </div>
                                    <div style="margin: 10px 0;">
                                        <label for="ftp_port"><?php _e('FTP Port:', 'yoco-backorder'); ?></label><br>
                                        <input type="number" id="ftp_port" name="ftp_port" value="<?php echo esc_attr($current_supplier['settings']['ftp_port'] ?? '21'); ?>" style="width: 80px;">
                                    </div>
                                    <div style="margin: 10px 0;">
                                        <label for="ftp_user"><?php _e('FTP Username:', 'yoco-backorder'); ?></label><br>
                                        <input type="text" id="ftp_user" name="ftp_user" value="<?php echo esc_attr($current_supplier['settings']['ftp_user'] ?? ''); ?>" class="regular-text">
                                    </div>
                                    <div style="margin: 10px 0;">
                                        <label for="ftp_pass"><?php _e('FTP Password:', 'yoco-backorder'); ?></label><br>
                                        <input type="password" id="ftp_pass" name="ftp_pass" value="<?php echo esc_attr($current_supplier['settings']['ftp_pass'] ?? ''); ?>" class="regular-text">
                                    </div>
                                    <div style="margin: 10px 0;">
                                        <label for="ftp_path"><?php _e('File Path:', 'yoco-backorder'); ?></label><br>
                                        <input type="text" id="ftp_path" name="ftp_path" value="<?php echo esc_attr($current_supplier['settings']['ftp_path'] ?? ''); ?>" class="regular-text" placeholder="/Joka.csv">
                                        <p class="description"><?php _e('Path to CSV file on FTP server (including filename)', 'yoco-backorder'); ?></p>
                                    </div>
                                    <div style="margin: 10px 0;">
                                        <label>
                                            <input type="checkbox" name="ftp_passive" value="1" <?php checked($current_supplier['settings']['ftp_passive'] ?? 1, 1); ?>>
                                            <?php _e('Use Passive Mode', 'yoco-backorder'); ?>
                                        </label>
                                    </div>
                                    <button type="button" id="test-ftp" class="button button-secondary">
                                        <?php _e('Test FTP Connection', 'yoco-backorder'); ?>
                                    </button>
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
    // Toggle between URL and FTP settings
    function toggleConnectionType() {
        var type = $('#connection_type').val();
        if (type === 'ftp') {
            $('#feed_url_row').hide();
            $('#ftp_settings_row').show();
            // DISABLE feed_url to prevent validation error
            $('#feed_url').prop('disabled', true).removeAttr('required');
        } else {
            $('#feed_url_row').show();
            $('#ftp_settings_row').hide();
            // ENABLE feed_url and make it required
            $('#feed_url').prop('disabled', false).attr('required', true);
        }
    }
    
    $('#connection_type').on('change', toggleConnectionType);
    toggleConnectionType(); // Initial state
    
    // Form validation before submit
    $('#yoco-supplier-form').on('submit', function(e) {
        var connectionType = $('#connection_type').val();
        
        if (connectionType === 'ftp') {
            // Validate FTP fields
            if (!$('#ftp_host').val() || !$('#ftp_user').val() || !$('#ftp_path').val()) {
                e.preventDefault();
                alert('<?php esc_js(_e("Please fill in FTP host, username and file path", "yoco-backorder")); ?>');
                return false;
            }
            // Clear feed_url when using FTP to prevent validation errors
            $('#feed_url').val('');
        } else {
            // Validate URL field
            if (!$('#feed_url').val()) {
                e.preventDefault();
                alert('<?php esc_js(_e("Please enter a feed URL", "yoco-backorder")); ?>');
                return false;
            }
            // Clear FTP fields when using URL
            $('#ftp_host, #ftp_user, #ftp_pass, #ftp_path').val('');
        }
    });
    
    // Test FTP connection
    $('#test-ftp').on('click', function() {
        var button = $(this);
        var data = {
            ftp_host: $('#ftp_host').val(),
            ftp_port: $('#ftp_port').val(),
            ftp_user: $('#ftp_user').val(),
            ftp_pass: $('#ftp_pass').val(),
            ftp_path: $('#ftp_path').val(),
            ftp_passive: $('#ftp_passive').is(':checked') ? 1 : 0
        };
        
        console.log('FTP Test Data:', data);
        
        if (!data.ftp_host || !data.ftp_user || !data.ftp_path) {
            alert('<?php esc_js(_e("Please fill in FTP host, username and file path", "yoco-backorder")); ?>');
            return;
        }
        
        button.prop('disabled', true).text('<?php esc_js(_e("Testing...", "yoco-backorder")); ?>');
        
        $.ajax({
            url: yoco_admin.ajax_url,
            type: 'POST',
            data: {
                action: 'yoco_test_ftp_connection',
                ...data,
                delimiter: $('#csv_delimiter').val(),
                nonce: yoco_admin.nonce
            },
            success: function(response) {
                console.log('FTP Test Response:', response);
                alert('FTP Test: ' + (response.success ? 'SUCCESS!' : 'FAILED: ' + response.message));
                
                if (response.success) {
                    var html = '<div style="background: #d4edda; border: 1px solid #c3e6cb; padding: 10px; border-radius: 3px;">';
                    html += '<strong><?php esc_js(_e("FTP Test Successful", "yoco-backorder")); ?></strong><br>';
                    
                    if (response.data) {
                        if (response.data.file_size) {
                            html += '<?php esc_js(_e("File size:", "yoco-backorder")); ?> ' + response.data.file_size + ' bytes<br>';
                        }
                        if (response.data.total_rows) {
                            html += '<?php esc_js(_e("Total rows:", "yoco-backorder")); ?> ' + response.data.total_rows + '<br>';
                        }
                        if (response.data.columns && response.data.columns.length > 0) {
                            html += '<?php esc_js(_e("Available columns:", "yoco-backorder")); ?> ' + response.data.columns.join(', ');
                            updateColumnSelects(response.data.columns);
                        }
                    } else {
                        html += '<?php esc_js(_e("Connection successful, but no data returned", "yoco-backorder")); ?>';
                    }
                    
                    html += '</div>';
                    $('#feed-test-result').html(html);
                    
                    updateColumnSelects(response.data.columns);
                } else {
                    // SHOW THE ACTUAL ERROR MESSAGE!
                    var html = '<div style="background: #f8d7da; border: 1px solid #f5c6cb; padding: 10px; border-radius: 3px;">';
                    html += '<strong><?php esc_js(_e("FTP Test Failed", "yoco-backorder")); ?></strong><br>';
                    html += response.message || 'Unknown error';
                    html += '</div>';
                    $('#feed-test-result').html(html);
                }
            },
            error: function(xhr, status, error) {
                console.log('FTP AJAX Error:', xhr, status, error);
                console.log('Response Text:', xhr.responseText);
                var html = '<div style="background: #f8d7da; border: 1px solid #f5c6cb; padding: 10px; border-radius: 3px;">';
                html += '<?php esc_js(_e("AJAX Error:", "yoco-backorder")); ?> ' + error + '<br>';
                html += 'Status: ' + status + '<br>';
                html += 'Response: ' + xhr.responseText.substring(0, 200);
                html += '</div>';
                $('#feed-test-result').html(html);
            },
            complete: function() {
                button.prop('disabled', false).text('<?php esc_js(_e("Test FTP Connection", "yoco-backorder")); ?>');
            }
        });
    });
    
    // Test feed functionality (existing code)
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
    $('#add-time').off('click').on('click', function() {
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