<?php
/**
 * YoCo Backorder System Install
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * YoCo_Install Class
 */
class YoCo_Install {
    
    /**
     * Plugin activation
     */
    public static function install() {
        if (!is_blog_installed()) {
            return;
        }
        
        // Check if we are not already running this routine
        if (self::is_installing()) {
            return;
        }
        
        // Set the installation flag
        set_transient('yoco_installing', 'yes', MINUTE_IN_SECONDS * 10);
        
        // Create database tables
        self::create_tables();
        
        // Create default options
        self::create_options();
        
        // Update version
        self::update_version();
        
        // Remove installation flag
        delete_transient('yoco_installing');
        
        do_action('yoco_installed');
    }
    
    /**
     * Check if installing
     */
    private static function is_installing() {
        return 'yes' === get_transient('yoco_installing');
    }
    
    /**
     * Create database tables
     */
    private static function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $tables = "
        CREATE TABLE IF NOT EXISTS {$wpdb->prefix}yoco_supplier_settings (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            supplier_term_id bigint(20) unsigned NOT NULL,
            feed_url text DEFAULT NULL,
            update_frequency int(11) DEFAULT 1,
            update_times text DEFAULT NULL,
            default_delivery_time varchar(255) DEFAULT '',
            csv_delimiter varchar(10) DEFAULT ',',
            csv_has_header tinyint(1) DEFAULT 1,
            sku_column varchar(50) DEFAULT '',
            stock_column varchar(50) DEFAULT '',
            mapping_config text DEFAULT NULL,
            is_active tinyint(1) DEFAULT 1,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY supplier_term_id (supplier_term_id)
        ) $charset_collate;
        
        CREATE TABLE IF NOT EXISTS {$wpdb->prefix}yoco_supplier_stock (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            product_id bigint(20) unsigned NOT NULL,
            supplier_term_id bigint(20) unsigned NOT NULL,
            sku varchar(100) DEFAULT '',
            ean varchar(50) DEFAULT '',
            stock_quantity int(11) DEFAULT 0,
            is_available tinyint(1) DEFAULT 0,
            last_updated datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY product_supplier (product_id, supplier_term_id),
            KEY supplier_term_id (supplier_term_id),
            KEY sku (sku),
            KEY ean (ean)
        ) $charset_collate;
        
        CREATE TABLE IF NOT EXISTS {$wpdb->prefix}yoco_sync_logs (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            supplier_term_id bigint(20) unsigned NOT NULL,
            sync_type varchar(50) DEFAULT 'manual',
            status varchar(20) DEFAULT 'pending',
            products_processed int(11) DEFAULT 0,
            products_updated int(11) DEFAULT 0,
            errors_count int(11) DEFAULT 0,
            error_messages text DEFAULT NULL,
            started_at datetime DEFAULT CURRENT_TIMESTAMP,
            completed_at datetime DEFAULT NULL,
            PRIMARY KEY (id),
            KEY supplier_term_id (supplier_term_id),
            KEY status (status),
            KEY started_at (started_at)
        ) $charset_collate;
        ";
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($tables);
    }
    
    /**
     * Create default options
     */
    private static function create_options() {
        $defaults = array(
            'yoco_version' => YOCO_BACKORDER_VERSION,
            'yoco_enable_frontend_display' => 'no',
            'yoco_frontend_text' => __('Available from supplier', 'yoco-backorder'),
            'yoco_cron_enabled' => 'no',
            'yoco_debug_mode' => 'no',
        );
        
        foreach ($defaults as $key => $value) {
            if (get_option($key) === false) {
                add_option($key, $value);
            }
        }
    }
    
    /**
     * Update version
     */
    private static function update_version() {
        delete_option('yoco_version');
        add_option('yoco_version', YOCO_BACKORDER_VERSION);
    }
    
    /**
     * Plugin deactivation
     */
    public static function deactivate() {
        // Clear scheduled cron jobs
        wp_clear_scheduled_hook('yoco_supplier_sync_cron');
        
        do_action('yoco_deactivated');
    }
    
    /**
     * Get database schema
     */
    public static function get_schema() {
        global $wpdb;
        
        return array(
            'supplier_settings' => $wpdb->prefix . 'yoco_supplier_settings',
            'supplier_stock' => $wpdb->prefix . 'yoco_supplier_stock',
            'sync_logs' => $wpdb->prefix . 'yoco_sync_logs'
        );
    }
}