<?php
/**
 * YoCo Backorder System Product Management
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * YoCo_Product Class
 */
class YoCo_Product {
    
    /**
     * The single instance of the class
     */
    protected static $_instance = null;
    
    /**
     * Main YoCo_Product Instance
     */
    public static function instance() {
        if (is_null(self::$_instance)) {
            self::$_instance = new self();
        }
        return self::$_instance;
    }
    
    /**
     * Constructor
     */
    public function __construct() {
        $this->init_hooks();
    }
    
    /**
     * Hook into actions and filters
     */
    private function init_hooks() {
        // Add YoCo tab to product data tabs
        add_filter('woocommerce_product_data_tabs', array($this, 'add_yoco_tab'));
        add_action('woocommerce_product_data_panels', array($this, 'add_yoco_tab_content'));
        add_action('woocommerce_process_product_meta', array($this, 'save_product_fields'));
        
        // Variation hooks  
        add_action('woocommerce_variation_options_pricing', array($this, 'add_variation_fields'), 10, 3);
        add_action('woocommerce_save_product_variation', array($this, 'save_variation_fields'), 10, 2);
        
        // Product list hooks
        add_filter('manage_edit-product_columns', array($this, 'add_product_list_columns'));
        add_action('manage_product_posts_custom_column', array($this, 'populate_product_list_columns'), 10, 2);
        
        // Bulk edit
        add_action('woocommerce_product_bulk_edit_end', array($this, 'bulk_edit_fields'));
        add_action('woocommerce_product_bulk_edit_save', array($this, 'save_bulk_edit_fields'));
        
        // Auto-update backorder status when stock changes
        add_action('woocommerce_product_set_stock', array($this, 'on_stock_change'));
        add_action('woocommerce_variation_set_stock', array($this, 'on_stock_change'));
        add_action('woocommerce_product_object_updated_props', array($this, 'on_product_updated'), 10, 2);
    }
    
    /**
     * Add YoCo tab to product data tabs
     */
    public function add_yoco_tab($tabs) {
        $tabs['yoco_backorder'] = array(
            'label' => __('YoCo Backorder', 'yoco-backorder'),
            'target' => 'yoco_backorder_data',
            'class' => array('show_if_simple', 'show_if_variable'),
            'priority' => 80
        );
        return $tabs;
    }
    
    /**
     * Add YoCo tab content
     */
    public function add_yoco_tab_content() {
        global $post;
        ?>
        <div id="yoco_backorder_data" class="panel woocommerce_options_panel">
            <?php $this->add_product_fields(); ?>
        </div>
        <?php
    }
    
    /**
     * Add YoCo fields to product edit page
     */
    public function add_product_fields() {
        global $post;
        
        echo '<div class="options_group">';
        echo '<h3>' . __('YoCo Backorder Settings', 'yoco-backorder') . '</h3>';
        
        $product = wc_get_product($post->ID);
        $is_variable = $product && $product->is_type('variable');
        
        if ($is_variable) {
            // For variable products, show parent-level control
            $parent_enabled = get_post_meta($post->ID, '_yoco_backorder_enabled', true);
            
            woocommerce_wp_checkbox(array(
                'id' => '_yoco_backorder_enabled',
                'label' => __('Enable YoCo for ALL variations', 'yoco-backorder'),
                'description' => __('This will enable/disable YoCo backorder for ALL variations of this product', 'yoco-backorder'),
                'value' => $parent_enabled
            ));
            
            // Show current variation status
            $variations = $product->get_children();
            if (!empty($variations)) {
                $enabled_count = 0;
                foreach ($variations as $variation_id) {
                    $enabled = get_post_meta($variation_id, '_yoco_backorder_enabled', true);
                    if ($enabled === 'yes') {
                        $enabled_count++;
                    }
                }
                
                echo '<p class="form-field">';
                echo '<label>' . __('Current Status:', 'yoco-backorder') . '</label>';
                echo '<span style="margin-left: 10px;">';
                if ($enabled_count === 0) {
                    echo '<span style="color: #dc3232;">' . sprintf(__('0 of %d variations enabled', 'yoco-backorder'), count($variations)) . '</span>';
                } elseif ($enabled_count === count($variations)) {
                    echo '<span style="color: #46b450;">' . sprintf(__('All %d variations enabled', 'yoco-backorder'), count($variations)) . '</span>';
                } else {
                    echo '<span style="color: #ffb900;">' . sprintf(__('%d of %d variations enabled', 'yoco-backorder'), $enabled_count, count($variations)) . '</span>';
                }
                echo '</span></p>';
            }
        } else {
            // For simple products, show simple control
            woocommerce_wp_checkbox(array(
                'id' => '_yoco_backorder_enabled',
                'label' => __('Enable YoCo Backorder', 'yoco-backorder'),
                'description' => __('Enable backorder management for this product via YoCo system', 'yoco-backorder'),
            ));
        }
        
        // Show supplier stock info
        $this->display_supplier_stock_info($post->ID);
        
        echo '</div>';
    }
    
    /**
     * Save product fields
     */
    public function save_product_fields($post_id) {
        $enabled = isset($_POST['_yoco_backorder_enabled']) ? 'yes' : 'no';
        $was_enabled = get_post_meta($post_id, '_yoco_backorder_enabled', true);
        
        $product = wc_get_product($post_id);
        
        // ALWAYS save parent setting
        update_post_meta($post_id, '_yoco_backorder_enabled', $enabled);
        
        if ($product && $product->is_type('variable')) {
            // For variable products, ALWAYS sync all variations with parent setting
            $variations = $product->get_children();
            foreach ($variations as $variation_id) {
                $was_variation_enabled = get_post_meta($variation_id, '_yoco_backorder_enabled', true);
                
                if ($enabled === 'yes') {
                    // Store default delivery time when first enabling YoCo
                    if ($was_variation_enabled !== 'yes') {
                        $this->store_default_delivery_time($variation_id);
                    }
                    update_post_meta($variation_id, '_yoco_backorder_enabled', $enabled);
                    
                    // Only auto-sync if enabled in settings
                    if (get_option('yoco_auto_sync_on_save', 'no') === 'yes') {
                        self::update_product_backorder_status($variation_id);
                    }
                } else {
                    // Disabling YoCo - restore original delivery time
                    if ($was_variation_enabled === 'yes') {
                        $this->restore_original_delivery_time($variation_id);
                    }
                    update_post_meta($variation_id, '_yoco_backorder_enabled', $enabled);
                }
            }
        } else {
            // For simple products
            if ($enabled === 'yes') {
                // Store default delivery time when first enabling YoCo
                if ($was_enabled !== 'yes') {
                    $this->store_default_delivery_time($post_id);
                }
                
                // Only auto-sync if enabled in settings
                if (get_option('yoco_auto_sync_on_save', 'no') === 'yes') {
                    self::update_product_backorder_status($post_id);
                }
            } else {
                // Disabling YoCo - restore original delivery time
                if ($was_enabled === 'yes') {
                    $this->restore_original_delivery_time($post_id);
                }
            }
        }
    }
    
    /**
     * Add fields to variation - remove YoCo setting, show only stock info
     */
    public function add_variation_fields($loop, $variation_data, $variation) {
        echo '<div class="form-row form-row-full">';
        
        // Just show supplier stock for variation
        $this->display_supplier_stock_info($variation->ID, true);
        
        echo '</div>';
    }
    
    /**
     * Save variation fields - YoCo is now controlled at parent level
     */
    public function save_variation_fields($variation_id, $i) {
        // YoCo setting is now controlled at parent level only
        // This function kept for compatibility but does nothing
    }
    
    /**
     * Display supplier stock information
     */
    private function display_supplier_stock_info($product_id, $is_variation = false) {
        $product = wc_get_product($product_id);
        if (!$product) {
            return;
        }
        
        // For variable products (parent), show stock per variation
        if ($product->is_type('variable') && !$is_variation) {
            $this->display_variable_product_supplier_stock($product_id);
            return;
        }
        
        // For simple products or variations, show normal stock info
        $supplier_stocks = $this->get_product_supplier_stocks($product_id);
        
        if (empty($supplier_stocks)) {
            echo '<p class="form-field">';
            echo '<em>' . __('No supplier stock data available. Check product supplier configuration.', 'yoco-backorder') . '</em>';
            echo '</p>';
            return;
        }
        
        echo '<div class="yoco-supplier-stock-info" style="margin: 10px 0; padding: 10px; background: #f9f9f9; border-left: 3px solid #2196F3;">';
        echo '<h4>' . __('Supplier Stock Information', 'yoco-backorder') . '</h4>';
        
        foreach ($supplier_stocks as $stock) {
            $supplier_term = get_term($stock['supplier_term_id'], 'pa_xcore_suppliers');
            if (!$supplier_term || is_wp_error($supplier_term)) {
                continue;
            }
            
            echo '<div style="margin: 5px 0;">';
            echo '<strong>' . esc_html($supplier_term->name) . ':</strong> ';
            echo '<span style="color: ' . ($stock['stock_quantity'] > 0 ? '#4CAF50' : '#f44336') . '">';
            echo $stock['stock_quantity'] . ' ' . ($stock['is_available'] ? __('available', 'yoco-backorder') : __('not available', 'yoco-backorder'));
            echo '</span>';
            if (!empty($stock['last_updated'])) {
                echo ' <small>(' . sprintf(__('Updated: %s', 'yoco-backorder'), 
                    wp_date(get_option('date_format') . ' ' . get_option('time_format'), strtotime($stock['last_updated']))) . ')</small>';
            }
            echo '</div>';
        }
        
        echo '<button type="button" class="button button-secondary yoco-check-stock" data-product-id="' . $product_id . '">';
        echo __('Refresh Supplier Stock', 'yoco-backorder');
        echo '</button>';
        
        echo '</div>';
    }
    
    /**
     * Display supplier stock for variable products (per variation)
     */
    private function display_variable_product_supplier_stock($product_id) {
        $product = wc_get_product($product_id);
        if (!$product || !$product->is_type('variable')) {
            return;
        }
        
        $variations = $product->get_children();
        if (empty($variations)) {
            echo '<p class="form-field">';
            echo '<em>' . __('No variations found for this product.', 'yoco-backorder') . '</em>';
            echo '</p>';
            return;
        }
        
        echo '<div class="yoco-supplier-stock-info" style="margin: 10px 0; padding: 10px; background: #f9f9f9; border-left: 3px solid #2196F3;">';
        echo '<h4>' . __('Supplier Stock Information by Variation', 'yoco-backorder') . '</h4>';
        
        foreach ($variations as $variation_id) {
            $variation = wc_get_product($variation_id);
            if (!$variation) continue;
            
            // Get variation attributes for display
            $attributes = $variation->get_variation_attributes();
            $variation_name = array();
            foreach ($attributes as $attr_name => $attr_value) {
                // Clean attribute name
                $clean_attr_name = str_replace('attribute_', '', $attr_name);
                
                // Get the attribute label
                $attr_label = wc_attribute_label($clean_attr_name);
                
                // Get the term name if this is a taxonomy attribute
                if (taxonomy_exists($clean_attr_name)) {
                    $term = get_term_by('slug', $attr_value, $clean_attr_name);
                    if ($term && !is_wp_error($term)) {
                        $attr_value = $term->name;
                    }
                }
                
                // Format the value (convert slugs like "78-4oz" to "4 oz.")
                $attr_value = $this->format_attribute_value($attr_value);
                
                $variation_name[] = $attr_label . ': ' . $attr_value;
            }
            $variation_display = implode(', ', $variation_name);
            
            // Get own stock
            $own_stock = $variation->get_stock_quantity();
            $manage_stock = $variation->get_manage_stock();
            
            // Get supplier stock
            $supplier_stocks = $this->get_product_supplier_stocks($variation_id);
            
            echo '<div style="border: 1px solid #ddd; margin: 8px 0; padding: 8px; background: white; border-radius: 3px;">';
            echo '<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 5px;">';
            echo '<strong style="color: #333;">' . esc_html($variation_display) . '</strong>';
            echo '<small style="color: #666;">ID: ' . $variation_id . '</small>';
            echo '</div>';
            
            // Show own stock
            if ($manage_stock) {
                $stock_color = ($own_stock > 0) ? '#4CAF50' : '#f44336';
                echo '<div style="margin: 3px 0;"><span style="color: #666;">Eigen voorraad:</span> <strong style="color: ' . $stock_color . ';">' . $own_stock . '</strong></div>';
            } else {
                echo '<div style="margin: 3px 0;"><span style="color: #666;">Eigen voorraad:</span> <em>Niet beheerd</em></div>';
            }
            
            // Show supplier stock
            if (!empty($supplier_stocks)) {
                foreach ($supplier_stocks as $stock) {
                    $supplier_term = get_term($stock['supplier_term_id'], 'pa_xcore_suppliers');
                    if (!$supplier_term || is_wp_error($supplier_term)) {
                        continue;
                    }
                    
                    echo '<div style="margin: 3px 0;">';
                    echo '<span style="color: #666;">' . esc_html($supplier_term->name) . ':</span> ';
                    echo '<span style="color: ' . ($stock['stock_quantity'] > 0 ? '#4CAF50' : '#f44336') . ';">';
                    echo $stock['stock_quantity'] . ' ' . ($stock['is_available'] ? __('available', 'yoco-backorder') : __('not available', 'yoco-backorder'));
                    echo '</span>';
                    echo '</div>';
                }
            } else {
                echo '<div style="margin: 3px 0; color: #999;"><em>' . __('No supplier stock data', 'yoco-backorder') . '</em></div>';
            }
            
            echo '</div>';
        }
        
        echo '<button type="button" class="button button-secondary yoco-check-stock" data-product-id="' . $product_id . '">';
        echo __('Refresh All Variation Stock', 'yoco-backorder');
        echo '</button>';
        
        // Add inline JavaScript for this button since admin.js might not be loaded
        ?>
        <script>
        jQuery(document).ready(function($) {
            $('.yoco-check-stock').on('click', function(e) {
                e.preventDefault();
                var button = $(this);
                var productId = button.data('product-id');
                
                button.prop('disabled', true).text('<?php esc_js(_e("Checking...", "yoco-backorder")); ?>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'yoco_check_product_stock',
                        product_id: productId,
                        nonce: '<?php echo wp_create_nonce('yoco_admin_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('✅ ' + response.message);
                            location.reload();
                        } else {
                            alert('❌ ' + response.message);
                        }
                    },
                    error: function() {
                        alert('❌ AJAX Error occurred');
                    },
                    complete: function() {
                        button.prop('disabled', false).text('<?php esc_js(_e("Refresh All Variation Stock", "yoco-backorder")); ?>');
                    }
                });
            });
        });
        </script>
        <?php
        
        echo '</div>';
    }
    
    /**
     * Get product supplier stocks
     */
    private function get_product_supplier_stocks($product_id) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'yoco_supplier_stock';
        return $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM {$table} WHERE product_id = %d ORDER BY last_updated DESC", $product_id),
            ARRAY_A
        );
    }
    
    /**
     * Check supplier stock for product
     */
    public static function check_supplier_stock($product_id) {
        $product = wc_get_product($product_id);
        if (!$product) {
            return array(
                'success' => false,
                'message' => __('Product not found', 'yoco-backorder')
            );
        }
        
        // Get product suppliers
        $suppliers = wp_get_post_terms($product_id, 'pa_xcore_suppliers');
        if (empty($suppliers) || is_wp_error($suppliers)) {
            return array(
                'success' => false,
                'message' => __('No suppliers configured for this product', 'yoco-backorder')
            );
        }
        
        $results = array();
        foreach ($suppliers as $supplier) {
            $result = YoCo_Sync::sync_single_product($product_id, $supplier->term_id);
            $results[] = array(
                'supplier' => $supplier->name,
                'result' => $result
            );
        }
        
        return array(
            'success' => true,
            'message' => __('Stock check completed', 'yoco-backorder'),
            'results' => $results
        );
    }
    
    /**
     * Add columns to product list
     */
    public function add_product_list_columns($columns) {
        $columns['yoco_backorder'] = __('YoCo Backorder', 'yoco-backorder');
        $columns['yoco_supplier_stock'] = __('Supplier Stock', 'yoco-backorder');
        return $columns;
    }
    
    /**
     * Populate product list columns
     */
    public function populate_product_list_columns($column, $post_id) {
        switch ($column) {
            case 'yoco_backorder':
                $product = wc_get_product($post_id);
                if (!$product) break;
                
                if ($product->is_type('variable')) {
                    // For variable products, check variations
                    $variations = $product->get_children();
                    $enabled_count = 0;
                    $total_count = count($variations);
                    
                    foreach ($variations as $variation_id) {
                        $enabled = get_post_meta($variation_id, '_yoco_backorder_enabled', true);
                        if ($enabled === 'yes') {
                            $enabled_count++;
                        }
                    }
                    
                    if ($enabled_count === 0) {
                        echo '<span style="color: #999;">✗ ' . __('Disabled', 'yoco-backorder') . '</span>';
                    } elseif ($enabled_count === $total_count) {
                        echo '<span style="color: #4CAF50;">✓ ' . sprintf(__('All %d enabled', 'yoco-backorder'), $total_count) . '</span>';
                    } else {
                        echo '<span style="color: #ffb900;">◐ ' . sprintf(__('%d of %d enabled', 'yoco-backorder'), $enabled_count, $total_count) . '</span>';
                    }
                } else {
                    // For simple products
                    $enabled = get_post_meta($post_id, '_yoco_backorder_enabled', true);
                    if ($enabled === 'yes') {
                        echo '<span style="color: #4CAF50;">✓ ' . __('Enabled', 'yoco-backorder') . '</span>';
                    } else {
                        echo '<span style="color: #999;">✗ ' . __('Disabled', 'yoco-backorder') . '</span>';
                    }
                }
                break;
                
            case 'yoco_supplier_stock':
                $product = wc_get_product($post_id);
                if (!$product) break;
                
                if ($product->is_type('variable')) {
                    // For variable products, show summary of variations
                    $variations = $product->get_children();
                    if (empty($variations)) {
                        echo '<span style="color: #999;">-</span>';
                        break;
                    }
                    
                    $variation_stocks = array();
                    foreach ($variations as $variation_id) {
                        $variation = wc_get_product($variation_id);
                        if (!$variation) continue;
                        
                        // Get variation display name
                        $attributes = $variation->get_variation_attributes();
                        $variation_name = array();
                        foreach ($attributes as $attr_name => $attr_value) {
                            // Clean attribute name and get proper term name
                            $clean_attr_name = str_replace('attribute_', '', $attr_name);
                            
                            if (taxonomy_exists($clean_attr_name)) {
                                $term = get_term_by('slug', $attr_value, $clean_attr_name);
                                if ($term && !is_wp_error($term)) {
                                    $attr_value = $term->name;
                                }
                            }
                            
                            // Format and shorten for column display
                            $formatted_value = $this->format_attribute_value($attr_value);
                            if (strlen($formatted_value) > 10) {
                                $formatted_value = substr($formatted_value, 0, 7) . '...';
                            }
                            $variation_name[] = $formatted_value;
                        }
                        $short_name = implode(', ', $variation_name);
                        
                        // Get own stock
                        $own_stock = $variation->get_stock_quantity();
                        $manage_stock = $variation->get_manage_stock();
                        
                        // Get supplier stocks
                        $stocks = $this->get_product_supplier_stocks($variation_id);
                        $supplier_stock = 0;
                        
                        if (!empty($stocks)) {
                            foreach ($stocks as $stock) {
                                if ($stock['stock_quantity'] > 0) {
                                    $supplier_stock = $stock['stock_quantity'];
                                    break;
                                }
                            }
                        }
                        
                        $stock_display = '';
                        if ($manage_stock && $own_stock > 0) {
                            $stock_display = "Eigen: {$own_stock}";
                        } elseif ($supplier_stock > 0) {
                            $stock_display = "Lev: {$supplier_stock}";
                        } else {
                            $stock_display = "0";
                        }
                        
                        $variation_stocks[] = "{$short_name}: {$stock_display}";
                    }
                    
                    foreach (array_slice($variation_stocks, 0, 3) as $var_stock) {
                        echo '<div style="font-size: 11px; margin: 1px 0;"><small>' . esc_html($var_stock) . '</small></div>';
                    }
                    
                    if (count($variation_stocks) > 3) {
                        echo '<div style="font-size: 10px; color: #999;"><em>+' . (count($variation_stocks) - 3) . ' more...</em></div>';
                    }
                    
                } else {
                    // For simple products
                    $stocks = $this->get_product_supplier_stocks($post_id);
                    if (empty($stocks)) {
                        echo '<span style="color: #999;">-</span>';
                    } else {
                        foreach ($stocks as $stock) {
                            $supplier_term = get_term($stock['supplier_term_id'], 'pa_xcore_suppliers');
                            if ($supplier_term && !is_wp_error($supplier_term)) {
                                echo '<div><small>' . esc_html($supplier_term->name) . ': ' . $stock['stock_quantity'] . '</small></div>';
                            }
                        }
                    }
                }
                break;
        }
    }
    
    /**
     * Add bulk edit fields
     */
    public function bulk_edit_fields() {
        ?>
        <div class="inline-edit-group">
            <label class="alignleft">
                <span class="title"><?php _e('YoCo Backorder', 'yoco-backorder'); ?></span>
                <select name="_yoco_backorder_enabled">
                    <option value=""><?php _e('— No change —', 'yoco-backorder'); ?></option>
                    <option value="yes"><?php _e('Enable', 'yoco-backorder'); ?></option>
                    <option value="no"><?php _e('Disable', 'yoco-backorder'); ?></option>
                </select>
            </label>
        </div>
        <?php
    }
    
    /**
     * Save bulk edit fields
     */
    public function save_bulk_edit_fields($product) {
        if (isset($_REQUEST['_yoco_backorder_enabled']) && !empty($_REQUEST['_yoco_backorder_enabled'])) {
            $enabled = sanitize_text_field($_REQUEST['_yoco_backorder_enabled']);
            update_post_meta($product->get_id(), '_yoco_backorder_enabled', $enabled);
        }
    }
    
    /**
     * Update product backorder status based on supplier stock
     */
    public static function update_product_backorder_status($product_id) {
        $product = wc_get_product($product_id);
        if (!$product) {
            return false;
        }
        
        // Check if YoCo backorder is enabled
        $enabled = get_post_meta($product_id, '_yoco_backorder_enabled', true);
        if ($enabled !== 'yes') {
            return false;
        }
        
        $current_stock = $product->get_stock_quantity();
        $manage_stock = $product->get_manage_stock();
        
        // If we don't manage stock or have stock available - reset to normal delivery
        if (!$manage_stock || ($current_stock > 0)) {
            // Product has stock or stock is not managed - reset to default delivery time
            $default_delivery = get_post_meta($product_id, '_yoco_default_delivery', true);
            if (empty($default_delivery)) {
                $default_delivery = 'Voor 17:00 uur besteld, dezelfde werkdag nog verzonden';
            }
            
            // Reset backorder settings - PURE DATABASE, NO WOOCOMMERCE HOOKS
            update_post_meta($product_id, '_backorders', 'no');
            update_post_meta($product_id, '_stock_status', 'instock');
            update_post_meta($product_id, 'rrp', $default_delivery);
            
            error_log("YOCO: Product {$product_id} has stock ({$current_stock}) - reset to normal delivery: {$default_delivery}");
            return true;
        }
        
        // Only check supplier stock if we have NO stock
        if ($current_stock <= 0) {
            // Check supplier stock
            global $wpdb;
            $table = $wpdb->prefix . 'yoco_supplier_stock';
            $supplier_stocks = $wpdb->get_results(
                $wpdb->prepare("SELECT * FROM {$table} WHERE product_id = %d ORDER BY last_updated DESC", $product_id),
                ARRAY_A
            );
            
            $has_supplier_stock = false;
            $supplier_delivery_time = '';
            
            foreach ($supplier_stocks as $stock) {
                if ($stock['stock_quantity'] > 0 && $stock['is_available']) {
                    $has_supplier_stock = true;
                    $supplier_settings = YoCo_Supplier::get_supplier_settings($stock['supplier_term_id']);
                    if (!empty($supplier_settings['default_delivery_time'])) {
                        $supplier_delivery_time = $supplier_settings['default_delivery_time'];
                    }
                    break;
                }
            }
            
            if ($has_supplier_stock) {
    update_post_meta($product_id, '_backorders', 'notify');
    update_post_meta($product_id, '_stock_status', 'onbackorder');
    if (!empty($supplier_delivery_time)) {
        update_post_meta($product_id, 'rrp', $supplier_delivery_time);
    }
    
    // Remove outofstock visibility term so product shows in catalog
    wp_remove_object_terms($product_id, 'outofstock', 'product_visibility');
                
                // Sync parent stock status if this is a variation
                self::sync_parent_stock_status($product_id);
                
                error_log("YOCO: Product {$product_id} no stock - set backorder with supplier delivery: {$supplier_delivery_time}");
            } else {
                // No supplier stock either - reset backorder but keep out of stock - PURE DATABASE
                update_post_meta($product_id, '_backorders', 'no');
                update_post_meta($product_id, '_stock_status', 'outofstock');
                
                error_log("YOCO: Product {$product_id} no stock and no supplier stock - out of stock");
            }
            
            return $has_supplier_stock;
        }
        
        return false;
    }
    
    /**
     * Update parent stock status when variation gets backorder
     */
    public static function sync_parent_stock_status($variation_id) {
        $variation = wc_get_product($variation_id);
        if (!$variation || !$variation->is_type('variation')) {
            return false;
        }
        
        $parent_id = $variation->get_parent_id();
        if (!$parent_id) return false;
        
        // Check if parent is currently out of stock
        $parent_stock_status = get_post_meta($parent_id, '_stock_status', true);
        
        if ($parent_stock_status === 'outofstock') {
            // Check if any variation is now on backorder
            $parent = wc_get_product($parent_id);
            if ($parent && $parent->is_type('variable')) {
                $variations = $parent->get_children();
                $has_backorder = false;
                
                foreach ($variations as $var_id) {
                    $var_backorders = get_post_meta($var_id, '_backorders', true);
                    $var_stock_status = get_post_meta($var_id, '_stock_status', true);
                    
                    if ($var_backorders === 'notify' || $var_stock_status === 'onbackorder') {
                        $has_backorder = true;
                        break;
                    }
                }
                
                // If any variation is on backorder, set parent to backorder too
                if ($has_backorder) {
    update_post_meta($parent_id, '_stock_status', 'onbackorder');
    
    // Remove outofstock visibility and clear cache
    wp_remove_object_terms($parent_id, 'outofstock', 'product_visibility');
    delete_transient('wc_product_children_' . $parent_id);
    wc_delete_product_transients($parent_id);
    
    error_log("YOCO: Parent {$parent_id} set to backorder due to variation {$variation_id}");
    return true;
}
            }
        }
        
        return false;
    }
    
    
    /**
     * Handle stock changes - update backorder status automatically
     */
    public function on_stock_change($product) {
        if (is_numeric($product)) {
            $product_id = $product;
        } else {
            $product_id = $product->get_id();
        }
        
        error_log("YOCO: Stock changed for product {$product_id} - updating backorder status");
        self::update_product_backorder_status($product_id);
    }
    
    /**
     * Handle product updates - check if stock-related properties changed
     */
    public function on_product_updated($product, $updated_props) {
        // Check if stock-related properties were updated
        $stock_props = array('stock_quantity', 'manage_stock', 'stock_status');
        $stock_changed = array_intersect($stock_props, $updated_props);
        
        if (!empty($stock_changed)) {
            error_log("YOCO: Product {$product->get_id()} stock properties updated: " . implode(', ', $stock_changed));
            self::update_product_backorder_status($product->get_id());
        }
    }
    
    /**
     * Store default delivery time when YoCo is first enabled
     */
    private function store_default_delivery_time($product_id) {
        // Only store if not already stored
        $existing = get_post_meta($product_id, '_yoco_default_delivery', true);
        if (empty($existing)) {
            $current_delivery = get_post_meta($product_id, 'rrp', true);
            if (empty($current_delivery)) {
                $current_delivery = 'Voor 17:00 uur besteld, dezelfde werkdag nog verzonden';
            }
            update_post_meta($product_id, '_yoco_default_delivery', $current_delivery);
        }
    }
    
    /**
     * Format attribute value from slug to readable format
     */
    private function format_attribute_value($value) {
        // Handle common patterns
        if (preg_match('/^(\d+)-(\d+)(oz|g|kg|ml|l)$/', $value, $matches)) {
            // Pattern: "78-4oz" → "4 oz."
            return $matches[2] . ' ' . $matches[3] . '.';
        }
        
        if (preg_match('/^(\w+)-(.+)$/', $value, $matches)) {
            // Pattern: "size-large" → "Large"  
            return ucfirst(str_replace('-', ' ', $matches[2]));
        }
        
        // Convert underscores/dashes to spaces and capitalize
        $formatted = str_replace(array('-', '_'), ' ', $value);
        $formatted = ucwords($formatted);
        
        return $formatted;
    }
    
    /**
     * Restore original delivery time when YoCo is disabled
     */
    private function restore_original_delivery_time($product_id) {
        $original_delivery = get_post_meta($product_id, '_yoco_default_delivery', true);
        if (!empty($original_delivery)) {
            // PURE DATABASE - NO WOOCOMMERCE HOOKS
            update_post_meta($product_id, 'rrp', $original_delivery);
            update_post_meta($product_id, '_backorders', 'no');
            update_post_meta($product_id, '_stock_status', 'instock');
            
            error_log("YOCO: Restored original delivery time for product {$product_id}: {$original_delivery}");
        }
    }
}