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
        add_action('wp_ajax_yoco_sync_supplier', array($this, 'ajax_sync_supplier'));
        add_action('wp_ajax_yoco_check_product_stock', array($this, 'ajax_check_product_stock'));
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
    }
    
    /**
     * Dashboard page
     */
    public function dashboard_page() {
        include YOCO_BACKORDER_PLUGIN_DIR . 'templates/admin/dashboard.php';
    }
    
    /**
     * Suppliers page
     */
    public function suppliers_page() {
        include YOCO_BACKORDER_PLUGIN_DIR . 'templates/admin/suppliers.php';
    }
    
    /**
     * Sync logs page
     */
    public function sync_logs_page() {
        include YOCO_BACKORDER_PLUGIN_DIR . 'templates/admin/sync-logs.php';
    }
    
    /**
     * Settings page
     */
    public function settings_page() {
        include YOCO_BACKORDER_PLUGIN_DIR . 'templates/admin/settings.php';
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
        
        $response = YoCo_Product::check_supplier_stock($product_id);
        
        wp_send_json($response);
    }
}