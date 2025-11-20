<?php
/**
 * Plugin Name: YourCoding Backorder System
 * Plugin URI: https://github.com/yourcodingNL/yoco-backorderystem
 * Description: Advanced backorder management system for WooCommerce with supplier stock integration
 * Version: 1.0.0
 * Author: YourCoding
 * Author URI: https://yourcoding.nl
 * Requires at least: 5.0
 * Tested up to: 6.4
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.0
 * Text Domain: yoco-backorder
 * Domain Path: /languages
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('YOCO_BACKORDER_VERSION', '1.0.0');
define('YOCO_BACKORDER_PLUGIN_FILE', __FILE__);
define('YOCO_BACKORDER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('YOCO_BACKORDER_PLUGIN_URL', plugin_dir_url(__FILE__));
define('YOCO_BACKORDER_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Check if WooCommerce is active
if (!class_exists('YoCo_Backorder_System')) {
    
    /**
     * Main YoCo Backorder System Class
     */
    class YoCo_Backorder_System {
        
        /**
         * Plugin version
         */
        public $version = '1.0.0';
        
        /**
         * The single instance of the class
         */
        protected static $_instance = null;
        
        /**
         * Main YoCo_Backorder_System Instance
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
            $this->define_constants();
            $this->includes();
            $this->init_hooks();
        }
        
        /**
         * Define constants
         */
        private function define_constants() {
            $this->define('YOCO_ABSPATH', dirname(YOCO_BACKORDER_PLUGIN_FILE) . '/');
        }
        
        /**
         * Define constant if not already set
         */
        private function define($name, $value) {
            if (!defined($name)) {
                define($name, $value);
            }
        }
        
        /**
         * Include required core files
         */
        public function includes() {
            // Core classes
            include_once YOCO_ABSPATH . 'includes/class-yoco-install.php';
            include_once YOCO_ABSPATH . 'includes/class-yoco-admin.php';
            include_once YOCO_ABSPATH . 'includes/class-yoco-supplier.php';
            include_once YOCO_ABSPATH . 'includes/class-yoco-product.php';
            include_once YOCO_ABSPATH . 'includes/class-yoco-sync.php';
            include_once YOCO_ABSPATH . 'includes/class-yoco-cron.php';
            
            // Functions file for shared utilities
            if (file_exists(YOCO_ABSPATH . 'includes/yoco-functions.php')) {
                include_once YOCO_ABSPATH . 'includes/yoco-functions.php';
            }
        }
        
        /**
         * Hook into actions and filters
         */
        private function init_hooks() {
            register_activation_hook(YOCO_BACKORDER_PLUGIN_FILE, array('YoCo_Install', 'install'));
            register_deactivation_hook(YOCO_BACKORDER_PLUGIN_FILE, array('YoCo_Install', 'deactivate'));
            
            add_action('init', array($this, 'init'), 0);
            add_action('plugins_loaded', array($this, 'check_woocommerce'), 10);
        }
        
        /**
         * Init YoCo when WordPress Initialises
         */
        public function init() {
            // Before init action
            do_action('yoco_before_init');
            
            // Set up localisation
            $this->load_plugin_textdomain();
            
            // Init admin
            if (is_admin()) {
                YoCo_Admin::instance();
            }
            
            // Init classes
            YoCo_Supplier::instance();
            YoCo_Product::instance();
            
            // After init action
            do_action('yoco_init');
        }
        
        /**
         * Check if WooCommerce is active
         */
        public function check_woocommerce() {
            if (!class_exists('WooCommerce')) {
                add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
                return;
            }
        }
        
        /**
         * WooCommerce fallback notice
         */
        public function woocommerce_missing_notice() {
            echo '<div class="error"><p><strong>' . sprintf(esc_html__('YoCo Backorder System requires WooCommerce to be installed and active. You can download %s here.', 'yoco-backorder'), '<a href="https://woocommerce.com/" target="_blank">WooCommerce</a>') . '</strong></p></div>';
        }
        
        /**
         * Load plugin textdomain
         */
        public function load_plugin_textdomain() {
            load_plugin_textdomain('yoco-backorder', false, dirname(YOCO_BACKORDER_PLUGIN_BASENAME) . '/languages');
        }
        
        /**
         * Get the plugin url
         */
        public function plugin_url() {
            return untrailingslashit(plugins_url('/', YOCO_BACKORDER_PLUGIN_FILE));
        }
        
        /**
         * Get the plugin path
         */
        public function plugin_path() {
            return untrailingslashit(plugin_dir_path(YOCO_BACKORDER_PLUGIN_FILE));
        }
        
        /**
         * Get Ajax URL
         */
        public function ajax_url() {
            return admin_url('admin-ajax.php', 'relative');
        }
    }
}

/**
 * Main instance of YoCo_Backorder_System
 */
function YoCo() {
    return YoCo_Backorder_System::instance();
}
add_action('init', function() {
    if (isset($_GET['yoco_cron_secret']) && $_GET['yoco_cron_secret'] === 'yoco_jokasport_2025_xK9mP2nQ7wL') {
        if (isset($_GET['action']) && $_GET['action'] === 'sync_all') {
            // Prevent duplicate runs
            if (get_transient('yoco_cron_running')) {
                echo 'Already running';
                exit;
            }
            set_transient('yoco_cron_running', true, 600);

// CLEAR ALL FEED CACHES FIRST - Forces fresh download
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->options} 
              WHERE option_name LIKE '_transient_yoco_feed_%' 
              OR option_name LIKE '_transient_yoco_ftp_feed_%'");

// Disable all output buffering
            while (ob_get_level()) ob_end_clean();
            
            // Send headers
            ignore_user_abort(true);
            set_time_limit(0);
            
            echo 'OK';
            
            // Flush everything
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } else {
                ob_start();
                header('Connection: close');
                header('Content-Length: 2');
                ob_end_flush();
                flush();
            }
            
            // Now sync
            $suppliers = YoCo_Supplier::get_suppliers();
            $synced = 0;
            foreach ($suppliers as $supplier) {
                if (!$supplier['settings']['is_active']) continue;
                
                $has_feed = !empty($supplier['settings']['feed_url']) || 
                           ($supplier['settings']['connection_type'] === 'ftp' && 
                            !empty($supplier['settings']['ftp_host']) && 
                            !empty($supplier['settings']['ftp_user']) && 
                            !empty($supplier['settings']['ftp_path']));
                
                if (!$has_feed) continue;
                
                YoCo_Sync::manual_sync($supplier['term_id']);
                $synced++;
            }
            
            delete_transient('yoco_cron_running');
            exit;
        }
    }
});

// Initialize the plugin
YoCo(); 