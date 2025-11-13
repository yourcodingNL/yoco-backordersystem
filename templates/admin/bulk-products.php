<?php
/**
 * Bulk Product Setup Admin Template
 */

if (!defined('ABSPATH')) {
    exit;
}

// Handle bulk update form submission
if (isset($_POST['bulk_update_products']) && wp_verify_nonce($_POST['yoco_bulk_nonce'], 'yoco_bulk_update')) {
    $supplier_id = intval($_POST['supplier_id']);
    $selected_products = isset($_POST['selected_products']) ? array_map('intval', $_POST['selected_products']) : array();
    $action = sanitize_text_field($_POST['bulk_action']);
    
    $updated = 0;
    
    if (!empty($selected_products)) {
        $meta_value = ($action === 'enable') ? 'yes' : 'no';
        // SIMPELE FIX: Vind parent products van geselecteerde variations en voeg ze toe
$parent_ids = array();
foreach ($selected_products as $product_id) {
    $product = wc_get_product($product_id);
    if ($product && $product->is_type('variation')) {
        $parent_id = $product->get_parent_id();
        if ($parent_id && !in_array($parent_id, $parent_ids)) {
            $parent_ids[] = $parent_id;
        }
    }
}

// Voeg parents toe aan selected products
if (!empty($parent_ids)) {
    $selected_products = array_merge($selected_products, $parent_ids);
    $selected_products = array_unique($selected_products);
}
        // BULK DATABASE UPDATE - NO INDIVIDUAL SAVES, NO WOOCOMMERCE HOOKS
        global $wpdb;
        
        $product_ids = implode(',', array_map('intval', $selected_products));
        
        if ($action === 'enable') {
            // Store default delivery times for products that don't have them yet
            foreach ($selected_products as $product_id) {
                $existing_default = get_post_meta($product_id, '_yoco_default_delivery', true);
                if (empty($existing_default)) {
                    $current_delivery = get_post_meta($product_id, 'rrp', true);
                    if (empty($current_delivery)) {
                        $current_delivery = 'Voor 17:00 uur besteld, dezelfde werkdag nog verzonden';
                    }
                    update_post_meta($product_id, '_yoco_default_delivery', $current_delivery);
                }
            }
        } else {
            // Restore original delivery times when disabling
            foreach ($selected_products as $product_id) {
                $original_delivery = get_post_meta($product_id, '_yoco_default_delivery', true);
                if (!empty($original_delivery)) {
                    update_post_meta($product_id, 'rrp', $original_delivery);
                    update_post_meta($product_id, '_backorders', 'no');
                }
            }
        }
        
        // Bulk update YoCo enabled status - PURE DATABASE
        $wpdb->query($wpdb->prepare("
            UPDATE {$wpdb->postmeta} 
            SET meta_value = %s 
            WHERE post_id IN ({$product_ids}) 
            AND meta_key = '_yoco_backorder_enabled'
        ", $meta_value));
        
        // Insert meta for products that don't have the meta key yet
        $existing_ids = $wpdb->get_col("
            SELECT DISTINCT post_id 
            FROM {$wpdb->postmeta} 
            WHERE post_id IN ({$product_ids}) 
            AND meta_key = '_yoco_backorder_enabled'
        ");
        
        $missing_ids = array_diff($selected_products, $existing_ids);
        foreach ($missing_ids as $product_id) {
            $wpdb->insert($wpdb->postmeta, array(
                'post_id' => $product_id,
                'meta_key' => '_yoco_backorder_enabled',
                'meta_value' => $meta_value
            ));
        }
        
        $updated = count($selected_products);
        
        $message = sprintf(
            __('%d products %s for YoCo backorder successfully (pure database update).', 'yoco-backorder'),
            $updated,
            ($action === 'enable') ? __('enabled', 'yoco-backorder') : __('disabled', 'yoco-backorder')
        );
        echo '<div class="notice notice-success"><p>' . $message . '</p></div>';
    }
}

// Get suppliers with configured feeds
$suppliers_with_feeds = array();
if (class_exists('YoCo_Supplier') && method_exists('YoCo_Supplier', 'get_suppliers')) {
    $all_suppliers = YoCo_Supplier::get_suppliers();
    foreach ($all_suppliers as $supplier) {
        // CHECK FOR BOTH URL AND FTP CONFIGURATION
        $has_feed_config = false;
        if (!empty($supplier['settings']['feed_url'])) {
            $has_feed_config = true; // URL mode
        } elseif ($supplier['settings']['connection_type'] === 'ftp' && 
                 !empty($supplier['settings']['ftp_host']) && 
                 !empty($supplier['settings']['ftp_user']) && 
                 !empty($supplier['settings']['ftp_path'])) {
            $has_feed_config = true; // FTP mode
        }
        
        if ($has_feed_config && $supplier['settings']['is_active']) {
            $suppliers_with_feeds[] = $supplier;
        }
    }
}

// Get current supplier
$current_supplier_id = isset($_GET['supplier']) ? intval($_GET['supplier']) : null;
$current_supplier = null;
if ($current_supplier_id) {
    foreach ($suppliers_with_feeds as $supplier) {
        if ($supplier['term_id'] == $current_supplier_id) {
            $current_supplier = $supplier;
            break;
        }
    }
}

// Get products for current supplier
$supplier_products = array();
if ($current_supplier) {
    $args = array(
        'post_type' => 'product',
        'posts_per_page' => -1,
        'post_status' => 'publish',
        'tax_query' => array(
            array(
                'taxonomy' => 'pa_xcore_suppliers',
                'field' => 'term_id',
                'terms' => $current_supplier_id,
            )
        )
    );
    
    $products = get_posts($args);
    
    // PERFORMANCE: Get all product IDs (including variations) in bulk
    $all_product_ids = array();
    $variation_parents = array();
    
    foreach ($products as $product_post) {
        $product = wc_get_product($product_post->ID);
        if (!$product) continue;
        
        if ($product->is_type('variable')) {
            $variations = $product->get_children();
            $all_product_ids = array_merge($all_product_ids, $variations);
            foreach ($variations as $variation_id) {
                $variation_parents[$variation_id] = array(
                    'parent_id' => $product_post->ID,
                    'parent_name' => $product->get_name()
                );
            }
        } else {
            $all_product_ids[] = $product_post->ID;
        }
    }
    
    // PERFORMANCE: Bulk query for YoCo enabled status - 1 query instead of thousands
    $yoco_enabled = array();
    if (!empty($all_product_ids)) {
        global $wpdb;
        $product_ids_string = implode(',', array_map('intval', $all_product_ids));
        $results = $wpdb->get_results("
            SELECT post_id, meta_value 
            FROM {$wpdb->postmeta} 
            WHERE post_id IN ({$product_ids_string}) 
            AND meta_key = '_yoco_backorder_enabled'
        ");
        
        foreach ($results as $result) {
            $yoco_enabled[$result->post_id] = $result->meta_value;
        }
    }
    
    // PERFORMANCE: Bulk load WC products - this caches them
    $wc_products = array();
    foreach ($all_product_ids as $product_id) {
        $wc_products[$product_id] = wc_get_product($product_id);
    }
    
    // Process products and their variations with cached data
    foreach ($products as $product_post) {
        $product = wc_get_product($product_post->ID);
        if (!$product) continue;
        
        if ($product->is_type('variable')) {
            // Add variations using cached data
            $variations = $product->get_children();
            foreach ($variations as $variation_id) {
                if (isset($wc_products[$variation_id])) {
                    $supplier_products[] = array(
                        'id' => $variation_id,
                        'product' => $wc_products[$variation_id],
                        'parent_id' => $product_post->ID,
                        'parent_name' => $product->get_name(),
                        'type' => 'variation',
                        'yoco_enabled' => isset($yoco_enabled[$variation_id]) ? $yoco_enabled[$variation_id] : 'no'
                    );
                }
            }
        } else {
            // Add simple product using cached data
            $supplier_products[] = array(
                'id' => $product_post->ID,
                'product' => $product,
                'parent_id' => null,
                'parent_name' => null,
                'type' => 'simple',
                'yoco_enabled' => isset($yoco_enabled[$product_post->ID]) ? $yoco_enabled[$product_post->ID] : 'no'
            );
        }
    }
}
?>

<div class="wrap">
    <h1><?php _e('YoCo Bulk Product Setup', 'yoco-backorder'); ?></h1>
    
    <div style="display: flex; gap: 20px; margin: 20px 0;">
        
        <!-- Supplier Selection -->
        <div class="card" style="flex: 0 0 350px;">
            <h2><?php _e('Select Supplier', 'yoco-backorder'); ?></h2>
            
            <?php if (empty($suppliers_with_feeds)): ?>
                <p><em><?php _e('No suppliers with configured feeds found.', 'yoco-backorder'); ?></em></p>
                <p><?php _e('Please configure at least one supplier feed first.', 'yoco-backorder'); ?></p>
                <a href="<?php echo admin_url('admin.php?page=yoco-suppliers'); ?>" class="button button-primary">
                    <?php _e('Configure Suppliers', 'yoco-backorder'); ?>
                </a>
            <?php else: ?>
                <div class="supplier-list">
                    <?php foreach ($suppliers_with_feeds as $supplier): ?>
                        <?php 
                        $is_selected = ($current_supplier_id == $supplier['term_id']);
                        ?>
                        <div class="supplier-item <?php echo $is_selected ? 'selected' : ''; ?>" style="padding: 15px; margin: 10px 0; border: 2px solid <?php echo $is_selected ? '#0073aa' : '#ddd'; ?>; border-radius: 5px; background: <?php echo $is_selected ? '#f0f8ff' : 'white'; ?>; cursor: pointer;" onclick="window.location.href='?page=yoco-bulk-products&supplier=<?php echo $supplier['term_id']; ?>';">
                            <h3 style="margin: 0 0 8px 0;"><?php echo esc_html($supplier['name']); ?></h3>
                            <div style="color: #666; font-size: 13px;">
                                <div>🔗 <?php _e('Feed configured', 'yoco-backorder'); ?></div>
                                <div>⚡ <?php _e('Click to load products', 'yoco-backorder'); ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Product Management -->
        <div class="card" style="flex: 1;">
            <?php if (!$current_supplier): ?>
                <h2><?php _e('Select a Supplier', 'yoco-backorder'); ?></h2>
                <p><?php _e('Choose a supplier from the left to see and manage their products.', 'yoco-backorder'); ?></p>
                
            <?php elseif (empty($supplier_products)): ?>
                <h2><?php echo sprintf(__('Products for: %s', 'yoco-backorder'), esc_html($current_supplier['name'])); ?></h2>
                <p><em><?php _e('No products found for this supplier.', 'yoco-backorder'); ?></em></p>
                <p><?php _e('Make sure products are assigned to this supplier via the "pa_xcore_suppliers" attribute.', 'yoco-backorder'); ?></p>
                
            <?php else: ?>
                <h2><?php echo sprintf(__('Products for: %s', 'yoco-backorder'), esc_html($current_supplier['name'])); ?></h2>
                <p><?php echo sprintf(__('Found %d products for this supplier.', 'yoco-backorder'), count($supplier_products)); ?></p>
                
                <form method="post" id="bulk-products-form">
                    <?php wp_nonce_field('yoco_bulk_update', 'yoco_bulk_nonce'); ?>
                    <input type="hidden" name="supplier_id" value="<?php echo $current_supplier_id; ?>">
                    
                    <div style="margin: 20px 0; padding: 15px; background: #f9f9f9; border-radius: 5px;">
                        <div style="display: flex; gap: 15px; align-items: center;">
                            <div>
                                <label>
                                    <input type="checkbox" id="select-all"> 
                                    <strong><?php _e('Select All', 'yoco-backorder'); ?></strong>
                                </label>
                            </div>
                            <div>
                                <select name="bulk_action" required>
                                    <option value=""><?php _e('Choose action...', 'yoco-backorder'); ?></option>
                                    <option value="enable"><?php _e('Enable YoCo Backorder', 'yoco-backorder'); ?></option>
                                    <option value="disable"><?php _e('Disable YoCo Backorder', 'yoco-backorder'); ?></option>
                                </select>
                            </div>
                            <div>
                                <input type="submit" name="bulk_update_products" class="button button-primary" value="<?php esc_attr_e('Apply to Selected', 'yoco-backorder'); ?>">
                            </div>
                        </div>
                    </div>
                    
                    <div class="product-list" style="max-height: 600px; overflow-y: auto; border: 1px solid #ddd; border-radius: 5px;">
                        <table class="widefat fixed striped">
                            <thead>
                                <tr>
                                    <th style="width: 40px;"><?php _e('Select', 'yoco-backorder'); ?></th>
                                    <th><?php _e('Product', 'yoco-backorder'); ?></th>
                                    <th style="width: 80px;"><?php _e('SKU', 'yoco-backorder'); ?></th>
                                    <th style="width: 120px;"><?php _e('YoCo Status', 'yoco-backorder'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($supplier_products as $item): ?>
                                    <?php 
                                    $product = $item['product'];
                                    if (!$product) continue;
                                    
                                    // Use cached YoCo enabled status
                                    $is_enabled = ($item['yoco_enabled'] === 'yes');
                                    ?>
                                    <tr>
                                        <td style="text-align: center;">
                                            <input type="checkbox" name="selected_products[]" value="<?php echo $item['id']; ?>" class="product-checkbox">
                                        </td>
                                        <td>
                                            <?php if ($item['type'] === 'variation'): ?>
                                                <strong><?php echo esc_html($item['parent_name']); ?></strong>
                                                <div style="color: #2271b1; font-size: 13px; margin: 2px 0;">
                                                    <?php
                                                    // Show variation attributes
                                                    $attributes = $product->get_variation_attributes();
                                                    $variation_text = array();
                                                    foreach ($attributes as $attr_name => $attr_value) {
                                                        $clean_attr_name = str_replace('attribute_', '', $attr_name);
                                                        $attr_label = wc_attribute_label($clean_attr_name);
                                                        
                                                        // Get proper term name if it's a taxonomy
                                                        if (taxonomy_exists($clean_attr_name)) {
                                                            $term = get_term_by('slug', $attr_value, $clean_attr_name);
                                                            if ($term && !is_wp_error($term)) {
                                                                $attr_value = $term->name;
                                                            }
                                                        }
                                                        
                                                        $variation_text[] = $attr_label . ': ' . $attr_value;
                                                    }
                                                    echo '↳ ' . implode(', ', $variation_text);
                                                    ?>
                                                </div>
                                            <?php else: ?>
                                                <strong><?php echo esc_html($product->get_name()); ?></strong>
                                            <?php endif; ?>
                                            <div style="color: #666; font-size: 12px;">
                                                ID: <?php echo $item['id']; ?> | 
                                                <?php echo ucfirst($product->get_type()); ?>
                                                <?php if ($product->get_stock_quantity() !== null): ?>
                                                    | <?php _e('Stock:', 'yoco-backorder'); ?> <?php echo $product->get_stock_quantity(); ?>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td>
                                            <code><?php echo esc_html($product->get_sku()); ?></code>
                                        </td>
                                        <td>
                                            <?php if ($is_enabled): ?>
                                                <span style="color: #46b450; font-weight: bold;">✅ <?php _e('Enabled', 'yoco-backorder'); ?></span>
                                            <?php else: ?>
                                                <span style="color: #dc3232;">❌ <?php _e('Disabled', 'yoco-backorder'); ?></span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Select all functionality
    $('#select-all').on('change', function() {
        $('.product-checkbox').prop('checked', $(this).prop('checked'));
    });
    
    // Update select all when individual checkboxes change
    $('.product-checkbox').on('change', function() {
        var total = $('.product-checkbox').length;
        var checked = $('.product-checkbox:checked').length;
        
        $('#select-all').prop('indeterminate', checked > 0 && checked < total);
        $('#select-all').prop('checked', checked === total);
    });
    
    // Form validation
    $('#bulk-products-form').on('submit', function(e) {
        var selected = $('.product-checkbox:checked').length;
        var action = $('select[name="bulk_action"]').val();
        
        if (selected === 0) {
            alert('<?php esc_js(_e("Please select at least one product.", "yoco-backorder")); ?>');
            e.preventDefault();
            return false;
        }
        
        if (!action) {
            alert('<?php esc_js(_e("Please choose an action.", "yoco-backorder")); ?>');
            e.preventDefault();
            return false;
        }
        
        var actionText = (action === 'enable') ? '<?php esc_js(_e("enable", "yoco-backorder")); ?>' : '<?php esc_js(_e("disable", "yoco-backorder")); ?>';
        var confirmMessage = '<?php esc_js(_e("Are you sure you want to", "yoco-backorder")); ?> ' + actionText + ' <?php esc_js(_e("YoCo backorder for", "yoco-backorder")); ?> ' + selected + ' <?php esc_js(_e("products?", "yoco-backorder")); ?>';
        
        if (!confirm(confirmMessage)) {
            e.preventDefault();
            return false;
        }
    });
});
</script>

<style>
.supplier-item:hover {
    border-color: #0073aa !important;
    background-color: #f8f9fa !important;
}

.supplier-item.selected {
    box-shadow: 0 2px 5px rgba(0,115,170,0.2);
}

.product-list table th {
    background: #f1f1f1;
    font-weight: 600;
}

.product-checkbox {
    transform: scale(1.2);
}

#select-all {
    transform: scale(1.3);
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}
</style>