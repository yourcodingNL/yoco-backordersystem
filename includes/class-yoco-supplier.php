<?php
/**
 * YoCo Backorder System Supplier Management
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * YoCo_Supplier Class
 */
class YoCo_Supplier {
    
    /**
     * The single instance of the class
     */
    protected static $_instance = null;
    
    /**
     * Main YoCo_Supplier Instance
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
        // Constructor logic if needed
    }
    
    /**
     * Get all suppliers from pa_xcore_suppliers taxonomy
     */
    public static function get_suppliers() {
        $terms = get_terms(array(
            'taxonomy' => 'pa_xcore_suppliers',
            'hide_empty' => false,
        ));
        
        if (is_wp_error($terms)) {
            return array();
        }
        
        $suppliers = array();
        foreach ($terms as $term) {
            $suppliers[] = array(
                'term_id' => $term->term_id,
                'name' => $term->name,
                'slug' => $term->slug,
                'settings' => self::get_supplier_settings($term->term_id)
            );
        }
        
        return $suppliers;
    }
    
    /**
     * Get supplier settings
     */
    public static function get_supplier_settings($term_id) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'yoco_supplier_settings';
        $settings = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE supplier_term_id = %d", $term_id),
            ARRAY_A
        );
        
        if (!$settings) {
            return self::get_default_settings();
        }
        
        // Parse JSON fields
        if (!empty($settings['update_times'])) {
            $settings['update_times'] = json_decode($settings['update_times'], true);
        }
        if (!empty($settings['mapping_config'])) {
            $settings['mapping_config'] = json_decode($settings['mapping_config'], true);
        }
        
        return $settings;
    }
    
    /**
     * Save supplier settings
     */
    public static function save_supplier_settings($term_id, $settings) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'yoco_supplier_settings';
        
        // Debug: Log what we're trying to save
        error_log("YOCO: Trying to save supplier {$term_id} with settings: " . print_r($settings, true));
        
        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table}'");
        if (!$table_exists) {
            error_log("YOCO: ERROR - Table {$table} does not exist!");
            return false;
        }
        
        // Prepare data
        $data = array(
            'supplier_term_id' => $term_id,
            'connection_type' => sanitize_text_field($settings['connection_type'] ?? 'url'),
            'feed_url' => sanitize_text_field($settings['feed_url']),
            'ftp_host' => sanitize_text_field($settings['ftp_host'] ?? ''),
            'ftp_port' => intval($settings['ftp_port'] ?? 21),
            'ftp_user' => sanitize_text_field($settings['ftp_user'] ?? ''),
            'ftp_pass' => sanitize_text_field($settings['ftp_pass'] ?? ''),
            'ftp_path' => sanitize_text_field($settings['ftp_path'] ?? ''),
            'ftp_passive' => intval($settings['ftp_passive'] ?? 0),
            'update_frequency' => intval($settings['update_frequency']),
            'update_times' => json_encode($settings['update_times']),
            'default_delivery_time' => sanitize_text_field($settings['default_delivery_time']),
            'csv_delimiter' => sanitize_text_field($settings['csv_delimiter']),
            'csv_has_header' => intval($settings['csv_has_header']),
            'sku_column' => sanitize_text_field($settings['sku_column']),
            'stock_column' => sanitize_text_field($settings['stock_column']),
            'mapping_config' => json_encode($settings['mapping_config']),
            'is_active' => intval($settings['is_active']),
        );
        
        // Debug: Log the prepared data
        error_log("YOCO: Prepared data: " . print_r($data, true));
        
        // Check if settings exist
        $existing = $wpdb->get_var(
            $wpdb->prepare("SELECT id FROM {$table} WHERE supplier_term_id = %d", $term_id)
        );
        
        if ($existing) {
            error_log("YOCO: Updating existing record ID: {$existing}");
            $result = $wpdb->update($table, $data, array('supplier_term_id' => $term_id));
        } else {
            error_log("YOCO: Creating new record");
            $result = $wpdb->insert($table, $data);
        }
        
        // Debug: Log the result
        if ($result === false) {
            error_log("YOCO: Database error: " . $wpdb->last_error);
            error_log("YOCO: Last query: " . $wpdb->last_query);
        } else {
            error_log("YOCO: Save successful, rows affected: " . $result);
        }
        
        return $result !== false;
    }
    
    /**
     * Test supplier feed
     */
    public static function test_feed($feed_url, $delimiter = ',') {
        if (empty($feed_url)) {
            return array(
                'success' => false,
                'message' => __('Feed URL is required', 'yoco-backorder')
            );
        }
        
        // Download and parse CSV
        $response = wp_remote_get($feed_url, array(
            'timeout' => 30,
            'user-agent' => 'YoCo-Backorder/' . YOCO_BACKORDER_VERSION
        ));
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => sprintf(__('Failed to fetch feed: %s', 'yoco-backorder'), $response->get_error_message())
            );
        }
        
        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            return array(
                'success' => false,
                'message' => __('Feed is empty', 'yoco-backorder')
            );
        }
        
        // Parse CSV
        $lines = str_getcsv($body, "\n");
        if (empty($lines)) {
            return array(
                'success' => false,
                'message' => __('No data found in feed', 'yoco-backorder')
            );
        }
        
        // Get header row
        $header = str_getcsv($lines[0], $delimiter);
        $sample_data = array();
        
        // Get first few data rows
        for ($i = 1; $i <= min(3, count($lines) - 1); $i++) {
            if (!empty($lines[$i])) {
                $sample_data[] = str_getcsv($lines[$i], $delimiter);
            }
        }
        
        return array(
            'success' => true,
            'message' => __('Feed test successful', 'yoco-backorder'),
            'data' => array(
                'total_rows' => count($lines),
                'columns' => $header,
                'sample_data' => $sample_data,
                'delimiter_detected' => self::detect_delimiter($lines[0])
            )
        );
    }
    
    /**
     * Auto-detect CSV delimiter
     */
    private static function detect_delimiter($line) {
        $delimiters = array(',', ';', '\t', '|');
        $delimiter_count = array();
        
        foreach ($delimiters as $delimiter) {
            $count = substr_count($line, $delimiter);
            if ($count > 0) {
                $delimiter_count[$delimiter] = $count;
            }
        }
        
        if (empty($delimiter_count)) {
            return ',';
        }
        
        return array_search(max($delimiter_count), $delimiter_count);
    }
    
    /**
     * Test FTP connection
     */
    public static function test_ftp_connection($host, $port, $user, $pass, $path, $passive, $delimiter = ',') {
        if (empty($host) || empty($user) || empty($path)) {
            return array(
                'success' => false,
                'message' => __('FTP host, username and file path are required', 'yoco-backorder')
            );
        }
        
        // Check if FTP extension is available
        if (!extension_loaded('ftp')) {
            return array(
                'success' => false,
                'message' => __('PHP FTP extension is not installed', 'yoco-backorder')
            );
        }
        
        try {
            // Connect to FTP server
            $conn = ftp_connect($host, $port, 30);
            if (!$conn) {
                throw new Exception(__('Could not connect to FTP server', 'yoco-backorder'));
            }
            
            // Login
            if (!ftp_login($conn, $user, $pass)) {
                ftp_close($conn);
                throw new Exception(__('FTP login failed - check username/password', 'yoco-backorder'));
            }
            
            // Set passive mode
            if ($passive) {
                ftp_pasv($conn, true);
            }
            
            // Check if file exists
            $file_size = ftp_size($conn, $path);
            if ($file_size === -1) {
                ftp_close($conn);
                throw new Exception(sprintf(__('File not found: %s', 'yoco-backorder'), $path));
            }
            
            // Download file content (first 1000 lines for testing)
            $temp_file = tempnam(sys_get_temp_dir(), 'yoco_ftp_test');
            if (!ftp_get($conn, $temp_file, $path, FTP_BINARY)) {
                ftp_close($conn);
                unlink($temp_file);
                throw new Exception(__('Failed to download file from FTP', 'yoco-backorder'));
            }
            
            ftp_close($conn);
            
            // Read and parse CSV content
            $content = file_get_contents($temp_file);
            unlink($temp_file);
            
            if (empty($content)) {
                throw new Exception(__('Downloaded file is empty', 'yoco-backorder'));
            }
            
            // Parse CSV like the regular test_feed function
            $lines = str_getcsv($content, "\n");
            if (empty($lines)) {
                throw new Exception(__('No data found in file', 'yoco-backorder'));
            }
            
            // Get header row
            $header = str_getcsv($lines[0], $delimiter);
            $sample_data = array();
            
            // Get first few data rows
            for ($i = 1; $i <= min(3, count($lines) - 1); $i++) {
                if (!empty($lines[$i])) {
                    $sample_data[] = str_getcsv($lines[$i], $delimiter);
                }
            }
            
            return array(
                'success' => true,
                'message' => __('FTP connection successful', 'yoco-backorder'),
                'data' => array(
                    'file_size' => $file_size,
                    'total_rows' => count($lines),
                    'columns' => $header,
                    'sample_data' => $sample_data,
                    'delimiter_detected' => self::detect_delimiter($lines[0])
                )
            );
            
        } catch (Exception $e) {
            return array(
                'success' => false,
                'message' => $e->getMessage()
            );
        }
    }
    
    /**
     * Get default settings
     */
    private static function get_default_settings() {
        return array(
            'connection_type' => 'url',
            'feed_url' => '',
            'ftp_host' => '',
            'ftp_port' => 21,
            'ftp_user' => '',
            'ftp_pass' => '',
            'ftp_path' => '',
            'ftp_passive' => 1,
            'update_frequency' => 1,
            'update_times' => array('09:00'),
            'default_delivery_time' => '3 tot 5 werkdagen',
            'csv_delimiter' => ',',
            'csv_has_header' => 1,
            'sku_column' => '',
            'stock_column' => '',
            'mapping_config' => array(),
            'is_active' => 1,
        );
    }
    
    /**
     * Get products for supplier - includes both parent products and simple products with YoCo enabled
     */
    public static function get_supplier_products($term_id) {
        $args = array(
            'post_type' => array('product', 'product_variation'), // RESTORE: Both types needed
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'meta_query' => array(
                array(
                    'key' => '_yoco_backorder_enabled',
                    'value' => 'yes',
                    'compare' => '='
                )
            ),
            'tax_query' => array(
                array(
                    'taxonomy' => 'pa_xcore_suppliers',
                    'field' => 'term_id',
                    'terms' => $term_id,
                )
            )
        );
        
        return get_posts($args);
    }
    
    /**
     * Delete supplier settings
     */
    public static function delete_supplier_settings($term_id) {
        global $wpdb;
        
        $table = $wpdb->prefix . 'yoco_supplier_settings';
        return $wpdb->delete($table, array('supplier_term_id' => $term_id));
    }
}