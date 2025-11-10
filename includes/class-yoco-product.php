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
        // Product edit page hooks
        add_action('woocommerce_product_options_general_product_data', array($this, 'add_product_fields'));
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
    }
    
    /**
     * Add YoCo fields to product edit page
     */
    public function add_product_fields() {
        global $post;
        
        echo '<div class="options_group">';
        echo '<h3>' . __('YoCo Backorder Settings', 'yoco-backorder') . '</h3>';
        
        // Enable backorder checkbox
        woocommerce_wp_checkbox(array(
            'id' => '_yoco_backorder_enabled',
            'label' => __('Enable YoCo Backorder', 'yoco-backorder'),
            'description' => __('Enable backorder management for this product via YoCo system', 'yoco-backorder'),
        ));
        
        // Show supplier stock info
        $this->display_supplier_stock_info($post->ID);
        
        echo '</div>';
    }
    
    /**
     * Save product fields
     */
    public function save_product_fields($post_id) {
        $enabled = isset($_POST['_yoco_backorder_enabled']) ? 'yes' : 'no';
        update_post_meta($post_id, '_yoco_backorder_enabled', $enabled);
        
        // If product is variable, apply to all variations if requested
        if (isset($_POST['_yoco_apply_to_variations']) && $_POST['_yoco_apply_to_variations'] === 'yes') {
            $product = wc_get_product($post_id);
            if ($product && $product->is_type('variable')) {
                $variations = $product->get_children();
                foreach ($variations as $variation_id) {
                    update_post_meta($variation_id, '_yoco_backorder_enabled', $enabled);
                }
            }
        }
    }
    
    /**
     * Add fields to variation
     */
    public function add_variation_fields($loop, $variation_data, $variation) {
        echo '<div class="form-row form-row-full">';
        
        woocommerce_wp_checkbox(array(
            'id' => "_yoco_backorder_enabled_{$loop}",
            'name' => "_yoco_backorder_enabled[{$loop}]",
            'label' => __('YoCo Backorder', 'yoco-backorder'),
            'value' => get_post_meta($variation->ID, '_yoco_backorder_enabled', true),
            'wrapper_class' => 'form-row form-row-full',
        ));
        
        // Show supplier stock for variation
        $this->display_supplier_stock_info($variation->ID, true);
        
        echo '</div>';
    }
    
    /**
     * Save variation fields
     */
    public function save_variation_fields($variation_id, $i) {
        $enabled = isset($_POST['_yoco_backorder_enabled'][$i]) ? 'yes' : 'no';
        update_post_meta($variation_id, '_yoco_backorder_enabled', $enabled);
    }
    
    /**
     * Display supplier stock information
     */
    private function display_supplier_stock_info($product_id, $is_variation = false) {
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
                $enabled = get_post_meta($post_id, '_yoco_backorder_enabled', true);
                if ($enabled === 'yes') {
                    echo '<span style="color: #4CAF50;">✓ ' . __('Enabled', 'yoco-backorder') . '</span>';
                } else {
                    echo '<span style="color: #999;">✗ ' . __('Disabled', 'yoco-backorder') . '</span>';
                }
                break;
                
            case 'yoco_supplier_stock':
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
        
        // Check current stock
        if ($product->get_stock_quantity() > 0) {
            // Product has stock, reset to default delivery time
            $default_delivery = get_post_meta($product_id, 'rrp', true);
            if (empty($default_delivery)) {
                $default_delivery = 'Voor 17:00 uur besteld, dezelfde werkdag nog verzonden';
            }
            update_post_meta($product_id, 'rrp', $default_delivery);
            return true;
        }
        
        // Check supplier stock
        $supplier_stocks = self::get_product_supplier_stocks($product_id);
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
            // Set backorder with supplier delivery time
            $product->set_backorders('notify');
            if (!empty($supplier_delivery_time)) {
                update_post_meta($product_id, 'rrp', $supplier_delivery_time);
            }
            $product->save();
        }
        
        return $has_supplier_stock;
    }
}