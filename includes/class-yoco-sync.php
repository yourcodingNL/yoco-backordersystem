<?php
/**
 * YoCo Backorder System Sync Management
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * YoCo_Sync Class
 */
class YoCo_Sync {
    
    /**
     * Manual sync supplier
     */
    public static function manual_sync($supplier_term_id) {
        // Create sync log
        $log_id = self::create_sync_log($supplier_term_id, 'manual');
        
        try {
            $supplier_settings = YoCo_Supplier::get_supplier_settings($supplier_term_id);
            
            if (!$supplier_settings) {
                throw new Exception(__('Supplier settings not found', 'yoco-backorder'));
            }
            
            // CHECK FOR BOTH URL AND FTP CONFIGURATION
            $has_feed_config = false;
            if (!empty($supplier_settings['feed_url'])) {
                $has_feed_config = true; // URL mode
            } elseif ($supplier_settings['connection_type'] === 'ftp' && 
                     !empty($supplier_settings['ftp_host']) && 
                     !empty($supplier_settings['ftp_user']) && 
                     !empty($supplier_settings['ftp_path'])) {
                $has_feed_config = true; // FTP mode
            }
            
            if (!$has_feed_config) {
                throw new Exception(__('Supplier feed URL or FTP connection not configured', 'yoco-backorder'));
            }
            
            if (!$supplier_settings['is_active']) {
                throw new Exception(__('Supplier is not active', 'yoco-backorder'));
            }
            
            // Download and process feed
            $result = self::process_supplier_feed($supplier_term_id, $supplier_settings);
            
            // Update sync log
            self::complete_sync_log($log_id, 'completed', $result);
            
            return array(
                'success' => true,
                'message' => sprintf(__('Sync completed. Processed %d products, updated %d.', 'yoco-backorder'), 
                    $result['processed'], $result['updated']),
                'data' => $result
            );
            
        } catch (Exception $e) {
            self::complete_sync_log($log_id, 'failed', null, $e->getMessage());
            
            return array(
                'success' => false,
                'message' => $e->getMessage()
            );
        }
    }
    
    /**
     * Sync single product
     */
    public static function sync_single_product($product_id, $supplier_term_id) {
        try {
            $supplier_settings = YoCo_Supplier::get_supplier_settings($supplier_term_id);
            
            if (!$supplier_settings || empty($supplier_settings['feed_url'])) {
                throw new Exception(__('Supplier feed URL not configured', 'yoco-backorder'));
            }
            
            $product = wc_get_product($product_id);
            if (!$product) {
                throw new Exception(__('Product not found', 'yoco-backorder'));
            }
            
            // Get product SKU or EAN
            $sku = $product->get_sku();
            $ean = get_post_meta($product_id, 'ean_13', true);
            
            if (empty($sku) && empty($ean)) {
                throw new Exception(__('Product has no SKU or EAN for matching', 'yoco-backorder'));
            }
            
            // Download feed (with caching)
            // Download feed - support both URL and FTP  
            if ($supplier_settings['connection_type'] === 'ftp') {
                $feed_data = self::get_ftp_feed_data($supplier_settings, $supplier_settings['csv_delimiter']);
            } else {
                $feed_data = self::get_feed_data($supplier_settings['feed_url'], $supplier_settings['csv_delimiter']);
            }
            
            // Find product in feed
            $stock_info = self::find_product_in_feed($feed_data, $sku, $ean, $supplier_settings);
            
            // Update supplier stock
            self::update_supplier_stock($product_id, $supplier_term_id, $stock_info, $sku, $ean);
            
            return array(
                'success' => true,
                'stock_quantity' => $stock_info['stock_quantity'],
                'is_available' => $stock_info['is_available']
            );
            
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => $e->getMessage()
            );
        }
    }
    
    /**
     * Process supplier feed
     */
    private static function process_supplier_feed($supplier_term_id, $settings) {
        $processed = 0;
        $updated = 0;
        $errors = array();
        
        // Get supplier products (both simple and variable)
        $products = YoCo_Supplier::get_supplier_products($supplier_term_id);
        
        if (empty($products)) {
            throw new Exception(__('No products found for this supplier with YoCo backorder enabled', 'yoco-backorder'));
        }
        
        // Download feed - support both URL and FTP
        if ($settings['connection_type'] === 'ftp') {
            $feed_data = self::get_ftp_feed_data($settings, $settings['csv_delimiter']);
        } else {
            $feed_data = self::get_feed_data($settings['feed_url'], $settings['csv_delimiter']);
        }
        
        $products_to_sync = array();
        
        // Collect all products/variations that need syncing
        foreach ($products as $post) {
            $product = wc_get_product($post->ID);
            if (!$product) continue;
            
            if ($product->is_type('variable')) {
                // Variable product parent - check if parent has YoCo enabled
                $parent_yoco_enabled = get_post_meta($post->ID, '_yoco_backorder_enabled', true);
                
                if ($parent_yoco_enabled === 'yes') {
                    // Parent has YoCo enabled - sync ALL variations
                    $variations = $product->get_children();
                    foreach ($variations as $variation_id) {
                        $variation = wc_get_product($variation_id);
                        if ($variation) {
                            // Ensure variation has YoCo enabled (sync with parent)
                            update_post_meta($variation_id, '_yoco_backorder_enabled', 'yes');
                            
                            $products_to_sync[] = array(
                                'id' => $variation_id,
                                'product' => $variation,
                                'type' => 'variation',
                                'parent_id' => $post->ID
                            );
                        }
                    }
                }
            } elseif ($product->is_type('variation')) {
                // Individual variation found (shouldn't happen with new logic, but handle it)
                $parent_id = $product->get_parent_id();
                $parent_yoco_enabled = get_post_meta($parent_id, '_yoco_backorder_enabled', true);
                
                if ($parent_yoco_enabled === 'yes') {
                    // Sync with parent setting
                    update_post_meta($post->ID, '_yoco_backorder_enabled', 'yes');
                    
                    $products_to_sync[] = array(
                        'id' => $post->ID,
                        'product' => $product,
                        'type' => 'variation',
                        'parent_id' => $parent_id
                    );
                }
            } else {
                // Simple product - use individual YoCo setting
                $yoco_enabled = get_post_meta($post->ID, '_yoco_backorder_enabled', true);
                if ($yoco_enabled === 'yes') {
                    $products_to_sync[] = array(
                        'id' => $post->ID,
                        'product' => $product,
                        'type' => 'simple',
                        'parent_id' => null
                    );
                }
            }
        }
        
        // Process each product/variation
        foreach ($products_to_sync as $item) {
            try {
                $processed++;
                $product = $item['product'];
                $product_id = $item['id'];
                
                $sku = $product->get_sku();
                $ean = get_post_meta($product_id, 'ean_13', true);
                
                if (empty($sku) && empty($ean)) {
                    $errors[] = sprintf(__('Product/Variation ID %d: No SKU or EAN for matching', 'yoco-backorder'), $product_id);
                    continue;
                }
                
                // Find in feed
                $stock_info = self::find_product_in_feed($feed_data, $sku, $ean, $settings);
                
                // Update stock
                if (self::update_supplier_stock($product_id, $supplier_term_id, $stock_info, $sku, $ean)) {
                    $updated++;
                    
                    // Update product backorder status
                    YoCo_Product::update_product_backorder_status($product_id);
                }
                
            } catch (Exception $e) {
                $errors[] = sprintf(__('Product ID %d: %s', 'yoco-backorder'), $product_id, $e->getMessage());
            }
        }
        
        return array(
            'processed' => $processed,
            'updated' => $updated,
            'errors' => $errors
        );
    }
    
    private static function get_ftp_feed_data($settings, $delimiter) {
    // Check cache first
    $cache_key = 'yoco_ftp_feed_' . md5($settings['ftp_host'] . $settings['ftp_path']);
    $cached_data = get_transient($cache_key);
    
    if ($cached_data !== false) {
        return $cached_data;
    }
    
    // Connect to FTP
    $ftp_connection = ftp_connect($settings['ftp_host'], $settings['ftp_port']);
    if (!$ftp_connection) {
        throw new Exception(sprintf(__('Failed to connect to FTP server %s:%d', 'yoco-backorder'), 
            $settings['ftp_host'], $settings['ftp_port']));
    }
    
    // Login to FTP
    $login = ftp_login($ftp_connection, $settings['ftp_user'], $settings['ftp_pass']);
    if (!$login) {
        ftp_close($ftp_connection);
        throw new Exception(__('Failed to login to FTP server', 'yoco-backorder'));
    }
    
    // Set passive mode if configured
    if ($settings['ftp_passive']) {
        ftp_pasv($ftp_connection, true);
    }
    
    // Create temporary file to download to
    $temp_file = tempnam(sys_get_temp_dir(), 'yoco_ftp_feed');
    
    // Download file from FTP
    if (!ftp_get($ftp_connection, $temp_file, $settings['ftp_path'], FTP_BINARY)) {
        ftp_close($ftp_connection);
        unlink($temp_file);
        throw new Exception(sprintf(__('Failed to download file %s from FTP server', 'yoco-backorder'), 
            $settings['ftp_path']));
    }
    
    // Close FTP connection
    ftp_close($ftp_connection);
    
    // Read and parse CSV file
    $csv_content = file_get_contents($temp_file);
    unlink($temp_file);
    
    if ($csv_content === false) {
        throw new Exception(__('Failed to read downloaded CSV file', 'yoco-backorder'));
    }
    
    // Normalize line endings
    $csv_content = str_replace(["\r\n", "\r"], "\n", $csv_content);
    
    // Parse CSV
    $lines = explode("\n", $csv_content);
    $data = array('header' => array(), 'rows' => array());
    $header_row = null;
    
    foreach ($lines as $line) {
        if (empty(trim($line))) continue;
        
        $row = str_getcsv($line, $delimiter);
        
        if ($header_row === null && $settings['csv_has_header']) {
            $header_row = $row;
            $data['header'] = $row;
            continue;
        }
        
        if ($header_row === null) {
            // No header, use numeric indexes
            $data['rows'][] = $row;
        } else {
            // Create associative array with headers
            $assoc_row = array();
            for ($i = 0; $i < count($row); $i++) {
                $key = isset($header_row[$i]) ? $header_row[$i] : "col_$i";
                $assoc_row[$key] = isset($row[$i]) ? $row[$i] : '';
            }
            $data['rows'][] = $assoc_row;
        }
    }
    
    // Cache for 10 minutes
    set_transient($cache_key, $data, 600);
    
    return $data;
}
    
    /**
     * Get feed data with caching
     */
    private static function get_feed_data($feed_url, $delimiter) {
        // Check cache first
        $cache_key = 'yoco_feed_' . md5($feed_url);
        $cached_data = get_transient($cache_key);
        
        if ($cached_data !== false) {
            return $cached_data;
        }
        
        // Download feed
        $response = wp_remote_get($feed_url, array(
            'timeout' => 60,
            'user-agent' => 'YoCo-Backorder/' . YOCO_BACKORDER_VERSION
        ));
        
        if (is_wp_error($response)) {
            throw new Exception(sprintf(__('Failed to download feed: %s', 'yoco-backorder'), $response->get_error_message()));
        }
        
        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            throw new Exception(__('Feed is empty', 'yoco-backorder'));
        }
        
        // Parse CSV
        $lines = str_getcsv($body, "\n");
        if (empty($lines)) {
            throw new Exception(__('No data in feed', 'yoco-backorder'));
        }
        
        // Parse header
        $header = str_getcsv($lines[0], $delimiter);
        $data = array('header' => $header, 'rows' => array());
        
        // Parse data rows
        for ($i = 1; $i < count($lines); $i++) {
            if (!empty(trim($lines[$i]))) {
                $row = str_getcsv($lines[$i], $delimiter);
                if (count($row) === count($header)) {
                    $data['rows'][] = array_combine($header, $row);
                }
            }
        }
        
        // Cache for 5 minutes
        set_transient($cache_key, $data, 5 * MINUTE_IN_SECONDS);
        
        return $data;
    }
    
    /**
     * Find product in feed data
     */
    private static function find_product_in_feed($feed_data, $sku, $ean, $settings) {
        $sku_column = $settings['sku_column'];
        $stock_column = $settings['stock_column'];
        
        if (empty($sku_column) || empty($stock_column)) {
            throw new Exception(__('SKU or Stock column not configured', 'yoco-backorder'));
        }
        
        foreach ($feed_data['rows'] as $row) {
            // Try SKU match first
            if (!empty($sku) && isset($row[$sku_column]) && $row[$sku_column] === $sku) {
                return self::parse_stock_info($row, $stock_column);
            }
            
            // Try EAN match if available
            if (!empty($ean) && isset($row['ean']) && $row['ean'] === $ean) {
                return self::parse_stock_info($row, $stock_column);
            }
        }
        
        // Not found in feed
        return array(
            'stock_quantity' => 0,
            'is_available' => false
        );
    }
    
    /**
     * Parse stock information from feed row
     */
    private static function parse_stock_info($row, $stock_column) {
        $stock_quantity = 0;
        
        if (isset($row[$stock_column])) {
            // Clean the stock value - remove all text, keep only numbers
            $raw_value = $row[$stock_column];
            $clean_value = self::clean_stock_value($raw_value);
            $stock_quantity = intval($clean_value);
        }
        
        return array(
            'stock_quantity' => $stock_quantity,
            'is_available' => $stock_quantity > 0
        );
    }
    
    /**
     * Clean stock value - remove text, keep only numbers
     */
    private static function clean_stock_value($value) {
        // Convert to string if not already
        $value = (string) $value;
        
        // Remove all non-digit characters (letters, spaces, symbols)
        $cleaned = preg_replace('/[^0-9]/', '', $value);
        
        // If empty after cleaning, return 0
        if (empty($cleaned)) {
            return 0;
        }
        
        return intval($cleaned);
    }
    
    /**
     * Update supplier stock in database
     */
    private static function update_supplier_stock($product_id, $supplier_term_id, $stock_info, $sku, $ean) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'yoco_supplier_stock';
        
        $data = array(
            'product_id' => $product_id,
            'supplier_term_id' => $supplier_term_id,
            'sku' => $sku,
            'ean' => $ean,
            'stock_quantity' => $stock_info['stock_quantity'],
            'is_available' => $stock_info['is_available'] ? 1 : 0,
            'last_updated' => current_time('mysql')
        );
        
        $existing = $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM {$table} WHERE product_id = %d AND supplier_term_id = %d", 
                $product_id, $supplier_term_id)
        );
        
        if ($existing) {
            $result = $wpdb->update($table, $data, array(
                'product_id' => $product_id,
                'supplier_term_id' => $supplier_term_id
            ));
        } else {
            $result = $wpdb->insert($table, $data);
        }
        
        return $result !== false;
    }
    
    /**
     * Create sync log
     */
    private static function create_sync_log($supplier_term_id, $sync_type) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'yoco_sync_logs';
        
        $wpdb->insert($table, array(
            'supplier_term_id' => $supplier_term_id,
            'sync_type' => $sync_type,
            'status' => 'running',
            'started_at' => current_time('mysql')
        ));
        
        return $wpdb->insert_id;
    }
    
    /**
     * Complete sync log
     */
    private static function complete_sync_log($log_id, $status, $result = null, $error_message = null) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'yoco_sync_logs';
        
        $data = array(
            'status' => $status,
            'completed_at' => current_time('mysql')
        );
        
        if ($result) {
            $data['products_processed'] = $result['processed'];
            $data['products_updated'] = $result['updated'];
            $data['errors_count'] = count($result['errors']);
            if (!empty($result['errors'])) {
                $data['error_messages'] = json_encode($result['errors']);
            }
        }
        
        if ($error_message) {
            $data['error_messages'] = $error_message;
            $data['errors_count'] = 1;
        }
        
        $wpdb->update($table, $data, array('id' => $log_id));
    }
    
    /**
     * Get sync logs
     */
    public static function get_sync_logs($supplier_term_id = null, $limit = 50) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'yoco_sync_logs';
        $where = '';
        
        if ($supplier_term_id) {
            $where = $wpdb->prepare(" WHERE supplier_term_id = %d", $supplier_term_id);
        }
        
        return $wpdb->get_results(
            "SELECT * FROM {$table}{$where} ORDER BY started_at DESC LIMIT {$limit}",
            ARRAY_A
        );
    }
    
    /**
     * Clean old logs
     */
    public static function clean_old_logs($days = 30) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'yoco_sync_logs';
        $date = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        return $wpdb->query(
            $wpdb->prepare("DELETE FROM {$table} WHERE started_at < %s", $date)
        );
    }
}