<?php
/**
 * YoCo Backorder System Admin
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * YoCo_Admin Class
 */
class YoCo_Admin {
    
    /**
     * The single instance of the class
     */
    protected static $_instance = null;
    
    /**
     * Main YoCo_Admin Instance
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
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));
        add_action('admin_init', array($this, 'admin_init'));
        
        // AJAX hooks
        add_action('wp_ajax_yoco_test_supplier_feed', array($this, 'ajax_test_supplier_feed'));
        add_action('wp_ajax_yoco_test_ftp_connection', array($this, 'ajax_test_ftp_connection'));
        add_action('wp_ajax_yoco_sync_supplier', array($this, 'ajax_sync_supplier'));
        add_action('wp_ajax_yoco_sync_all_active_suppliers', array($this, 'ajax_sync_all_active_suppliers'));
        add_action('wp_ajax_yoco_check_product_stock', array($this, 'ajax_check_product_stock'));
        add_action('wp_ajax_yoco_upgrade_database', array($this, 'ajax_upgrade_database'));
        add_action('wp_ajax_yoco_clean_old_logs', array($this, 'ajax_clean_old_logs'));
        add_action('wp_ajax_yoco_sync_all_suppliers', array($this, 'ajax_sync_all_suppliers'));
        add_action('wp_ajax_yoco_test_cron_now', array($this, 'ajax_test_cron_now'));
        add_action('wp_ajax_yoco_clear_cron_logs', array($this, 'ajax_clear_cron_logs'));
        add_action('wp_ajax_yoco_cron_heartbeat', array($this, 'ajax_cron_heartbeat'));
    }
    
    /**
     * Add admin menu
     */
    public function admin_menu() {
        add_menu_page(
            __('YoCo Backorder', 'yoco-backorder'),
            __('YoCo Backorder', 'yoco-backorder'),
            'manage_woocommerce',
            'yoco-backorder',
            array($this, 'dashboard_page'),
            'dashicons-update',
            56
        );
        
        add_submenu_page(
            'yoco-backorder',
            __('Dashboard', 'yoco-backorder'),
            __('Dashboard', 'yoco-backorder'),
            'manage_woocommerce',
            'yoco-backorder',
            array($this, 'dashboard_page')
        );
        
        add_submenu_page(
            'yoco-backorder',
            __('Suppliers', 'yoco-backorder'),
            __('Suppliers', 'yoco-backorder'),
            'manage_woocommerce',
            'yoco-suppliers',
            array($this, 'suppliers_page')
        );
        
        add_submenu_page(
            'yoco-backorder',
            __('Bulk Product Setup', 'yoco-backorder'),
            __('Bulk Product Setup', 'yoco-backorder'),
            'manage_woocommerce',
            'yoco-bulk-products',
            array($this, 'bulk_products_page')
        );
        
        add_submenu_page(
            'yoco-backorder',
            __('Sync Logs', 'yoco-backorder'),
            __('Sync Logs', 'yoco-backorder'),
            'manage_woocommerce',
            'yoco-sync-logs',
            array($this, 'sync_logs_page')
        );
        
        add_submenu_page(
            'yoco-backorder',
            __('Settings', 'yoco-backorder'),
            __('Settings', 'yoco-backorder'),
            'manage_woocommerce',
            'yoco-settings',
            array($this, 'settings_page')
        );
    }
    
    /**
     * Enqueue admin scripts
     */
    public function admin_scripts($hook) {
        if (strpos($hook, 'yoco-') === false) {
            return;
        }
        
        wp_enqueue_script(
            'yoco-admin',
            YOCO_BACKORDER_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            YOCO_BACKORDER_VERSION,
            true
        );
        
        wp_localize_script('yoco-admin', 'yoco_admin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('yoco_admin_nonce'),
            'current_time' => current_time('mysql'),
            'timezone' => wp_timezone_string(),
            'i18n' => array(
                'testing_feed' => __('Testing feed...', 'yoco-backorder'),
                'syncing' => __('Syncing...', 'yoco-backorder'),
                'checking_stock' => __('Checking stock...', 'yoco-backorder'),
                'success' => __('Success', 'yoco-backorder'),
                'error' => __('Error', 'yoco-backorder'),
            )
        ));
        
        wp_enqueue_style(
            'yoco-admin',
            YOCO_BACKORDER_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            YOCO_BACKORDER_VERSION
        );
    }
    
    /**
     * Admin init
     */
    public function admin_init() {
        // Register settings
        register_setting('yoco_settings', 'yoco_enable_frontend_display');
        register_setting('yoco_settings', 'yoco_frontend_text');
        register_setting('yoco_settings', 'yoco_cron_enabled');
        register_setting('yoco_settings', 'yoco_debug_mode');
        register_setting('yoco_settings', 'yoco_auto_sync_on_save');
    }
    
    /**
     * Dashboard page
     */
    public function dashboard_page() {
        $template_file = YOCO_BACKORDER_PLUGIN_DIR . 'templates/admin/dashboard.php';
        if (file_exists($template_file)) {
            include $template_file;
        } else {
            echo '<div class="wrap"><h1>YoCo Dashboard</h1><p>Template file not found.</p></div>';
        }
    }
    
    /**
     * Suppliers page
     */
    public function suppliers_page() {
        $template_file = YOCO_BACKORDER_PLUGIN_DIR . 'templates/admin/suppliers.php';
        if (file_exists($template_file)) {
            include $template_file;
        } else {
            echo '<div class="wrap"><h1>YoCo Suppliers</h1><p>Template file not found.</p></div>';
        }
    }
    
    /**
     * Sync logs page
     */
    public function sync_logs_page() {
        $template_file = YOCO_BACKORDER_PLUGIN_DIR . 'templates/admin/sync-logs.php';
        if (file_exists($template_file)) {
            include $template_file;
        } else {
            echo '<div class="wrap"><h1>YoCo Sync Logs</h1><p>Template file not found.</p></div>';
        }
    }
    
    /**
     * Settings page
     */
    public function settings_page() {
        $template_file = YOCO_BACKORDER_PLUGIN_DIR . 'templates/admin/settings.php';
        if (file_exists($template_file)) {
            include $template_file;
        } else {
            echo '<div class="wrap"><h1>YoCo Settings</h1><p>Template file not found.</p></div>';
        }
    }
    
    /**
     * Bulk products page
     */
    public function bulk_products_page() {
        $template_file = YOCO_BACKORDER_PLUGIN_DIR . 'templates/admin/bulk-products.php';
        if (file_exists($template_file)) {
            include $template_file;
        } else {
            echo '<div class="wrap"><h1>YoCo Bulk Products</h1><p>Template file not found.</p></div>';
        }
    }
    
    /**
     * AJAX: Test supplier feed
     */
    public function ajax_test_supplier_feed() {
        check_ajax_referer('yoco_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'yoco-backorder'));
        }
        
        $feed_url = sanitize_text_field($_POST['feed_url']);
        $delimiter = sanitize_text_field($_POST['delimiter']);
        
        $response = YoCo_Supplier::test_feed($feed_url, $delimiter);
        
        wp_send_json($response);
    }
    
    /**
     * AJAX: Test FTP connection
     */
    public function ajax_test_ftp_connection() {
        check_ajax_referer('yoco_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'yoco-backorder'));
        }
        
        $ftp_host = sanitize_text_field($_POST['ftp_host']);
        $ftp_port = intval($_POST['ftp_port']);
        $ftp_user = sanitize_text_field($_POST['ftp_user']);
        $ftp_pass = sanitize_text_field($_POST['ftp_pass']);
        $ftp_path = sanitize_text_field($_POST['ftp_path']);
        $ftp_passive = intval($_POST['ftp_passive']);
        $delimiter = sanitize_text_field($_POST['delimiter']);
        
        $response = YoCo_Supplier::test_ftp_connection($ftp_host, $ftp_port, $ftp_user, $ftp_pass, $ftp_path, $ftp_passive, $delimiter);
        
        wp_send_json($response);
    }
    
    /**
     * AJAX: Sync supplier
     */
    public function ajax_sync_supplier() {
        check_ajax_referer('yoco_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'yoco-backorder'));
        }
        
        $supplier_id = intval($_POST['supplier_id']);
        
        $response = YoCo_Sync::manual_sync($supplier_id);
        
        wp_send_json($response);
    }
    
    /**
     * AJAX: Check product stock
     */
    public function ajax_check_product_stock() {
        check_ajax_referer('yoco_admin_nonce', 'nonce');
        
        if (!current_user_can('edit_products')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'yoco-backorder'));
        }
        
        $product_id = intval($_POST['product_id']);
        $product = wc_get_product($product_id);
        
        if (!$product) {
            wp_send_json(array(
                'success' => false,
                'message' => __('Product not found', 'yoco-backorder')
            ));
            return;
        }
        
        if ($product->is_type('variable')) {
            // For variable products, check all variations
            $variations = $product->get_children();
            $results = array();
            $total_checked = 0;
            $total_updated = 0;
            
            foreach ($variations as $variation_id) {
                $variation = wc_get_product($variation_id);
                if (!$variation) continue;
                
                $yoco_enabled = get_post_meta($variation_id, '_yoco_backorder_enabled', true);
                if ($yoco_enabled !== 'yes') continue;
                
                $result = YoCo_Product::check_supplier_stock($variation_id);
                if ($result['success']) {
                    $total_updated++;
                }
                $total_checked++;
            }
            
            wp_send_json(array(
                'success' => true,
                'message' => sprintf(__('Checked %d variations, updated %d with supplier stock.', 'yoco-backorder'), 
                    $total_checked, $total_updated)
            ));
        } else {
            // For simple products, use existing logic
            $response = YoCo_Product::check_supplier_stock($product_id);
            wp_send_json($response);
        }
    }
    
    /**
     * AJAX: Clean old logs
     */
    public function ajax_clean_old_logs() {
        check_ajax_referer('yoco_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'yoco-backorder'));
        }
        
        // Clean ALL logs, not just old ones
        global $wpdb;
        $table = $wpdb->prefix . 'yoco_sync_logs';
        
        $total_logs = $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
        $deleted = $wpdb->query("TRUNCATE TABLE {$table}");
        
        wp_send_json(array(
            'success' => true,
            'message' => sprintf(__('Successfully cleaned ALL %d log entries.', 'yoco-backorder'), $total_logs)
        ));
    }
    
    /**
     * AJAX: Sync all active suppliers
     */
    public function ajax_sync_all_suppliers() {
        check_ajax_referer('yoco_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'yoco-backorder'));
        }
        
        // EMERGENCY FIX: Ensure variable product parents have correct YoCo settings
        $this->fix_variable_product_parents();
        
        // Get all active suppliers with feeds (URL or FTP)
        $suppliers = YoCo_Supplier::get_suppliers();
        $active_suppliers = array();
        
        foreach ($suppliers as $supplier) {
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
                $active_suppliers[] = $supplier;
            }
        }
        
        if (empty($active_suppliers)) {
            wp_send_json(array(
                'success' => false,
                'message' => __('No active suppliers with configured feeds found.', 'yoco-backorder')
            ));
            return;
        }
        
        $progress = array();
        $total_processed = 0;
        $total_updated = 0;
        $errors = array();
        
        foreach ($active_suppliers as $index => $supplier) {
            $progress[] = "🔄 Syncing supplier: " . $supplier['name'];
            
            try {
                // Show appropriate source info
                if ($supplier['settings']['connection_type'] === 'ftp') {
                    $progress[] = "📥 Downloading CSV from FTP: " . $supplier['settings']['ftp_host'] . $supplier['settings']['ftp_path'];
                } else {
                    $progress[] = "📥 Downloading CSV feed from: " . $supplier['settings']['feed_url'];
                }
                $progress[] = "🔍 Parsing CSV with delimiter: '" . $supplier['settings']['csv_delimiter'] . "'";
                
                $result = YoCo_Sync::manual_sync($supplier['term_id']);
                
                if ($result['success']) {
                    $progress[] = "✅ " . $supplier['name'] . ": Processed " . $result['data']['processed'] . " items, updated " . $result['data']['updated'];
                    $progress[] = "🗑️ CSV cache cleaned for " . $supplier['name'];
                    $total_processed += $result['data']['processed'];
                    $total_updated += $result['data']['updated'];
                    
                    if (!empty($result['data']['errors'])) {
                        $errors = array_merge($errors, $result['data']['errors']);
                        $progress[] = "⚠️ " . count($result['data']['errors']) . " errors occurred";
                    }
                } else {
                    $progress[] = "❌ " . $supplier['name'] . ": " . $result['message'];
                    $errors[] = $supplier['name'] . ': ' . $result['message'];
                }
                
                $progress[] = ""; // Empty line for spacing
                
            } catch (Exception $e) {
                $progress[] = "💥 " . $supplier['name'] . ": Exception - " . $e->getMessage();
                $errors[] = $supplier['name'] . ': Exception - ' . $e->getMessage();
            }
        }
        
        $progress[] = "🏁 Sync completed!";
        $progress[] = "📊 Total: " . count($active_suppliers) . " suppliers, " . $total_processed . " items processed, " . $total_updated . " updated";
        
        if (!empty($errors)) {
            $progress[] = "⚠️ " . count($errors) . " errors occurred - check logs for details";
        }
        
        // Auto-cleanup old logs (keep only last 100 entries)
        global $wpdb;
        $log_table = $wpdb->prefix . 'yoco_sync_logs';
        $total_logs = $wpdb->get_var("SELECT COUNT(*) FROM {$log_table}");
        
        if ($total_logs > 100) {
            $logs_to_delete = $total_logs - 100;
            $wpdb->query("DELETE FROM {$log_table} ORDER BY started_at ASC LIMIT {$logs_to_delete}");
            $progress[] = "🗑️ Auto-cleaned {$logs_to_delete} old log entries (keeping last 100)";
        }
        
        wp_send_json(array(
            'success' => true,
            'message' => sprintf(
                __('Synced %d suppliers. Processed %d items, updated %d.', 'yoco-backorder'),
                count($active_suppliers),
                $total_processed,
                $total_updated
            ),
            'progress' => $progress,
            'data' => array(
                'suppliers_synced' => count($active_suppliers),
                'total_processed' => $total_processed,
                'total_updated' => $total_updated,
                'errors' => $errors
            )
        ));
    }
    
    /**
     * Fix variable product parents that are missing YoCo settings
     */
    private function fix_variable_product_parents() {
        global $wpdb;
        
        // Find variable products where variations have YoCo enabled but parent doesn't
        $query = "
            SELECT DISTINCT p.post_parent 
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
            LEFT JOIN {$wpdb->postmeta} pm_parent ON p.post_parent = pm_parent.post_id AND pm_parent.meta_key = '_yoco_backorder_enabled'
            WHERE p.post_type = 'product_variation'
            AND pm.meta_key = '_yoco_backorder_enabled' 
            AND pm.meta_value = 'yes'
            AND (pm_parent.meta_value IS NULL OR pm_parent.meta_value != 'yes')
            AND p.post_parent > 0
        ";
        
        $parent_ids = $wpdb->get_col($query);
        
        foreach ($parent_ids as $parent_id) {
            // Set parent to YoCo enabled
            update_post_meta($parent_id, '_yoco_backorder_enabled', 'yes');
        }
        
        error_log("YOCO: Fixed " . count($parent_ids) . " variable product parents");
    }
    
    /**
     * AJAX: Test cron job now
     */
    public function ajax_test_cron_now() {
        check_ajax_referer('yoco_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'yoco-backorder'));
        }
        
        // Log that we're doing a manual test
        error_log('YOCO CRON: Manual test triggered from dashboard');
        
        // FORCE RUN SCHEDULE CHECK FIRST
        YoCo_Cron::schedule_events();
        
        // Run the sync function
        YoCo_Cron::sync_suppliers_cron();
        
        wp_send_json(array(
            'success' => true,
            'message' => __('Cron test completed! Check WordPress error logs for results.', 'yoco-backorder')
        ));
    }
    
    /**
     * AJAX: Clear cron logs
     */
    public function ajax_clear_cron_logs() {
        check_ajax_referer('yoco_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'yoco-backorder'));
        }
        
        // Clear cron history
        delete_option('yoco_cron_history');
        delete_option('yoco_last_test_sync');
        
        wp_send_json(array(
            'success' => true,
            'message' => __('Cron logs cleared successfully.', 'yoco-backorder')
        ));
    }
    
    /**
     * AJAX: Cron heartbeat - self-triggering cron system
     */
    public function ajax_cron_heartbeat() {
        check_ajax_referer('yoco_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'yoco-backorder'));
        }
        
        // Check if cron is enabled
        if (get_option('yoco_cron_enabled') !== 'yes') {
            wp_send_json(array(
                'success' => true,
                'debug' => 'Cron disabled - heartbeat idle'
            ));
            return;
        }
        
        // DIRECT SYNC CHECK - BYPASS WORDPRESS CRON COMPLETELY
        $test_mode = get_option('yoco_cron_test_mode', 'no') === 'yes';
        $last_heartbeat_sync = get_option('yoco_last_heartbeat_sync', 0);
        $current_time = current_time('timestamp');
        
        if ($test_mode) {
            $interval = get_option('yoco_cron_test_interval', 5) * 60; // minutes to seconds
            
            if ($current_time - $last_heartbeat_sync >= $interval) {
                // TIME FOR SYNC!
                update_option('yoco_last_heartbeat_sync', $current_time);
                
                // Run sync directly
                YoCo_Cron::sync_suppliers_cron();
                
                wp_send_json(array(
                    'success' => true,
                    'debug' => 'Heartbeat triggered sync (test mode)'
                ));
            } else {
                $remaining = $interval - ($current_time - $last_heartbeat_sync);
                wp_send_json(array(
                    'success' => true,
                    'debug' => "Waiting {$remaining}s for next sync"
                ));
            }
        } else {
            // NORMAL MODE - Check sync times
            wp_send_json(array(
                'success' => true,
                'debug' => 'Heartbeat active (normal mode)'
            ));
        }
    }
}