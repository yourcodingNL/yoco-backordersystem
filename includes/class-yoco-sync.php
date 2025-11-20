<?php
/**
 * YoCo Backorder System Sync Management - COMPLETE FIX
 * 
 * FIXES:
 * 1. Increased timeout from 30s to 120s for large CSV feeds
 * 2. Fixed double quote stripping in CSV parsing
 * 3. Better error handling for timeout issues
 * 4. FIXED: Trailing comma handling (both header AND data rows)
 */

if (!defined('ABSPATH')) {
    exit;
}

class YoCo_Sync {
    
    public static function manual_sync($supplier_term_id) {
        $log_id = self::create_sync_log($supplier_term_id, 'manual');
        
        try {
            $supplier_settings = YoCo_Supplier::get_supplier_settings($supplier_term_id);
            
            if (!$supplier_settings) {
                throw new Exception(__('Supplier settings not found', 'yoco-backorder'));
            }
            
            $has_feed_config = false;
            if (!empty($supplier_settings['feed_url'])) {
                $has_feed_config = true;
            } elseif ($supplier_settings['connection_type'] === 'ftp' && 
                     !empty($supplier_settings['ftp_host']) && 
                     !empty($supplier_settings['ftp_user']) && 
                     !empty($supplier_settings['ftp_path'])) {
                $has_feed_config = true;
            }
            
            if (!$has_feed_config) {
                throw new Exception(__('Supplier feed URL or FTP connection not configured', 'yoco-backorder'));
            }
            
            if (!$supplier_settings['is_active']) {
                throw new Exception(__('Supplier is not active', 'yoco-backorder'));
            }
            
            $result = self::process_supplier_feed($supplier_term_id, $supplier_settings);
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
            
            $sku = $product->get_sku();
            $ean = get_post_meta($product_id, 'ean_13', true);
            
            if (empty($sku) && empty($ean)) {
                throw new Exception(__('Product has no SKU or EAN for matching', 'yoco-backorder'));
            }
            
            if ($supplier_settings['connection_type'] === 'ftp') {
                $feed_data = self::get_ftp_feed_data($supplier_settings, $supplier_settings['csv_delimiter']);
            } else {
                $feed_data = self::get_feed_data($supplier_settings['feed_url'], $supplier_settings['csv_delimiter']);
            }
            
            $stock_info = self::find_product_in_feed($feed_data, $sku, $ean, $supplier_settings);
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
    
    private static function process_supplier_feed($supplier_term_id, $settings) {
        $processed = 0;
        $updated = 0;
        $errors = array();
        
        $products = YoCo_Supplier::get_supplier_products($supplier_term_id);
        
        if (empty($products)) {
            throw new Exception(__('No products found for this supplier with YoCo backorder enabled', 'yoco-backorder'));
        }
        
        if ($settings['connection_type'] === 'ftp') {
            $feed_data = self::get_ftp_feed_data($settings, $settings['csv_delimiter']);
        } else {
            $feed_data = self::get_feed_data($settings['feed_url'], $settings['csv_delimiter']);
        }
        
        $products_to_sync = array();
        
        foreach ($products as $post) {
            $product = wc_get_product($post->ID);
            if (!$product) continue;
            
            if ($product->is_type('variable')) {
                $parent_yoco_enabled = get_post_meta($post->ID, '_yoco_backorder_enabled', true);
                
                if ($parent_yoco_enabled === 'yes') {
                    $variations = $product->get_children();
                    foreach ($variations as $variation_id) {
                        $variation = wc_get_product($variation_id);
                        if ($variation) {
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
                $parent_id = $product->get_parent_id();
                $parent_yoco_enabled = get_post_meta($parent_id, '_yoco_backorder_enabled', true);
                
                if ($parent_yoco_enabled === 'yes') {
                    update_post_meta($post->ID, '_yoco_backorder_enabled', 'yes');
                    
                    $products_to_sync[] = array(
                        'id' => $post->ID,
                        'product' => $product,
                        'type' => 'variation',
                        'parent_id' => $parent_id
                    );
                }
            } else {
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
                
                $stock_info = self::find_product_in_feed($feed_data, $sku, $ean, $settings);
                
                if (self::update_supplier_stock($product_id, $supplier_term_id, $stock_info, $sku, $ean)) {
                    $updated++;
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
        $cache_key = 'yoco_ftp_feed_' . md5($settings['ftp_host'] . $settings['ftp_user'] . $settings['ftp_path']);
        $cached_data = get_transient($cache_key);
        
        if ($cached_data !== false) {
            return $cached_data;
        }
        
        $ftp_connection = ftp_connect($settings['ftp_host'], $settings['ftp_port'], 120);
        if (!$ftp_connection) {
            throw new Exception(sprintf(__('Failed to connect to FTP server %s:%d', 'yoco-backorder'), 
                $settings['ftp_host'], $settings['ftp_port']));
        }
        
        $login = ftp_login($ftp_connection, $settings['ftp_user'], $settings['ftp_pass']);
        if (!$login) {
            ftp_close($ftp_connection);
            throw new Exception(__('Failed to login to FTP server', 'yoco-backorder'));
        }
        
        if ($settings['ftp_passive']) {
            ftp_pasv($ftp_connection, true);
        }
        
        $temp_file = tempnam(sys_get_temp_dir(), 'yoco_ftp_feed');
        
        if (!ftp_get($ftp_connection, $temp_file, $settings['ftp_path'], FTP_BINARY)) {
            ftp_close($ftp_connection);
            unlink($temp_file);
            throw new Exception(sprintf(__('Failed to download file %s from FTP server', 'yoco-backorder'), 
                $settings['ftp_path']));
        }
        
        ftp_close($ftp_connection);
        
        $csv_content = file_get_contents($temp_file);
        unlink($temp_file);
        
        if ($csv_content === false) {
            throw new Exception(__('Failed to read downloaded CSV file', 'yoco-backorder'));
        }
        
        $csv_content = str_replace(["\r\n", "\r"], "\n", $csv_content);
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
                $data['rows'][] = $row;
            } else {
                $assoc_row = array();
                for ($i = 0; $i < count($row); $i++) {
                    $key = isset($header_row[$i]) ? $header_row[$i] : "col_$i";
                    $assoc_row[$key] = isset($row[$i]) ? $row[$i] : '';
                }
                $data['rows'][] = $assoc_row;
            }
        }
        
        set_transient($cache_key, $data, 600);
        return $data;
    }
    
    /**
     * Get feed data with caching - COMPLETE FIX VERSION
     * 
     * FIXES:
     * 1. Increased timeout from 60s to 120s for large feeds
     * 2. Removed double quote stripping (str_getcsv already handles this)
     * 3. Better error messages for timeout issues
     * 4. FIXED: Proper trailing comma handling for BOTH header AND data rows
     */
    private static function get_feed_data($feed_url, $delimiter) {
        $cache_key = 'yoco_feed_' . md5($feed_url);
        $cached_data = get_transient($cache_key);
        
        if ($cached_data !== false) {
            error_log("YOCO: Using cached feed data for {$feed_url}");
            return $cached_data;
        }
        
        error_log("YOCO: Downloading fresh feed from {$feed_url}");
        
        // FIX 1: INCREASED TIMEOUT TO 120 SECONDS
        $response = wp_remote_get($feed_url, array(
            'timeout' => 120,
            'user-agent' => 'YoCo-Backorder/' . YOCO_BACKORDER_VERSION,
            'sslverify' => false
        ));
        
        if (is_wp_error($response)) {
            $error_msg = $response->get_error_message();
            
            if (strpos($error_msg, 'timed out') !== false || strpos($error_msg, 'timeout') !== false) {
                throw new Exception(sprintf(
                    __('Feed download timeout after 120 seconds. Feed may be too large or server too slow. Original error: %s', 'yoco-backorder'),
                    $error_msg
                ));
            }
            
            throw new Exception(sprintf(__('Failed to download feed: %s', 'yoco-backorder'), $error_msg));
        }
        
        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            throw new Exception(__('Feed is empty', 'yoco-backorder'));
        }
        
        $body_size = strlen($body);
        error_log("YOCO: Downloaded feed size: " . round($body_size / 1024 / 1024, 2) . " MB");
        
        $lines = str_getcsv($body, "\n");
        if (empty($lines)) {
            throw new Exception(__('No data in feed', 'yoco-backorder'));
        }
        
        error_log("YOCO: Feed has " . count($lines) . " lines");
        
        // FIX 2 & 4: Parse header and remove trailing empty columns
        $header_raw = str_getcsv($lines[0], $delimiter);
        
        // Remove TRAILING empty values (caused by trailing comma like: "col1","col2",)
        // But keep empty values in the MIDDLE (they might be intentional)
        $header = $header_raw;
        while (count($header) > 0 && trim(end($header)) === '') {
            array_pop($header);
        }
        
        // Also trim whitespace from column names
        $header = array_map('trim', $header);
        
        error_log("YOCO: Feed header (" . count($header) . " columns): " . implode(', ', $header));
        error_log("YOCO: Original header had " . count($header_raw) . " columns (removed " . (count($header_raw) - count($header)) . " trailing empty)");
        
        $data = array('header' => $header, 'rows' => array());
        
        // Parse data rows
        $skipped_rows = 0;
        for ($i = 1; $i < count($lines); $i++) {
            if (empty(trim($lines[$i]))) continue;
            
            $row_raw = str_getcsv($lines[$i], $delimiter);
            
            // FIX 4: Remove TRAILING empty values from data rows (same as header)
            $row = $row_raw;
            while (count($row) > 0 && trim(end($row)) === '') {
                array_pop($row);
            }
            
            // Now check if row matches header column count
            if (count($row) === count($header)) {
                $data['rows'][] = array_combine($header, $row);
            } else {
                $skipped_rows++;
                if ($skipped_rows <= 3) {
                    // Log first few mismatches for debugging
                    error_log("YOCO: Row $i column mismatch - has " . count($row) . " columns, expected " . count($header));
                    error_log("YOCO: Row data: " . implode('|', array_slice($row, 0, 10))); // First 10 columns
                }
            }
        }
        
        error_log("YOCO: Successfully parsed " . count($data['rows']) . " data rows" . ($skipped_rows > 0 ? " (skipped {$skipped_rows} mismatched rows)" : ""));
        
        // Cache for 5 minutes
        set_transient($cache_key, $data, 5 * MINUTE_IN_SECONDS);
        
        return $data;
    }
    
    private static function find_product_in_feed($feed_data, $sku, $ean, $settings) {
        $sku_column = $settings['sku_column'];
        $stock_column = $settings['stock_column'];
        $match_on = $settings['match_on'] ?? 'sku';
        
        if (empty($sku_column) || empty($stock_column)) {
            throw new Exception(__('SKU or Stock column not configured', 'yoco-backorder'));
        }
        
        foreach ($feed_data['rows'] as $row) {
            if ($match_on === 'ean') {
                if (!empty($ean) && isset($row[$sku_column]) && $row[$sku_column] === $ean) {
                    return self::parse_stock_info($row, $stock_column);
                }
            } else {
                if (!empty($sku) && isset($row[$sku_column]) && $row[$sku_column] === $sku) {
                    return self::parse_stock_info($row, $stock_column);
                }
            }
        }
        
        return array(
            'stock_quantity' => 0,
            'is_available' => false
        );
    }
    
    private static function parse_stock_info($row, $stock_column) {
        $stock_quantity = 0;
        
        if (isset($row[$stock_column])) {
            $raw_value = $row[$stock_column];
            $cleaned = preg_replace('/[^0-9.\-]/', '', $raw_value);
            $stock_quantity = (int) floatval($cleaned);
            $stock_quantity = max(0, $stock_quantity);
        }
        
        return array(
            'stock_quantity' => $stock_quantity,
            'is_available' => $stock_quantity > 0
        );
    }
    
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
    
    public static function clean_old_logs($days = 30) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'yoco_sync_logs';
        $date = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        return $wpdb->query(
            $wpdb->prepare("DELETE FROM {$table} WHERE started_at < %s", $date)
        );
    }
}