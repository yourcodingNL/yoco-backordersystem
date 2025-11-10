# YoCo - Backorder System

Advanced backorder management system for WooCommerce with supplier stock integration for Jokasport.

## Features

### Core Functionality
- **Supplier Management**: Configure multiple suppliers with individual feed URLs and settings
- **CSV Feed Processing**: Automatic parsing and mapping of supplier stock feeds
- **Product Integration**: Per-product backorder settings with parent-child support
- **Manual & Automatic Sync**: Flexible synchronization scheduling
- **Comprehensive Logging**: Detailed sync logs with error tracking

### Key Components
- **Leverancier Integratie**: Uses existing `pa_xcore_suppliers` product taxonomy
- **Stock Tracking**: Real-time supplier stock visibility in admin
- **Backorder Logic**: Intelligent stock status updates based on supplier availability
- **Delivery Time Management**: Custom delivery times per supplier

## Installation

1. **Upload Plugin**
   ```bash
   # Upload to WordPress plugins directory
   /wp-content/plugins/yoco-backordersystem/
   ```

2. **Activate Plugin**
   - Go to WordPress Admin → Plugins
   - Find "YoCo - Backorder System"
   - Click "Activate"

3. **Database Setup**
   - Plugin automatically creates required database tables on activation
   - Tables: `yoco_supplier_settings`, `yoco_supplier_stock`, `yoco_sync_logs`

## Configuration

### 1. Supplier Setup
1. Navigate to **YoCo Backorder → Suppliers**
2. Select a supplier from the existing `pa_xcore_suppliers` taxonomy
3. Configure:
   - Feed URL
   - CSV delimiter and mapping
   - Update frequency and times
   - Default delivery time text

### 2. CSV Mapping
- Test feed to see available columns
- Map SKU/EAN columns for product matching
- Map stock quantity columns
- Auto-detection of delimiters

### 3. Product Settings
- **Individual Products**: Enable YoCo backorder in product edit page
- **Variable Products**: Apply to all variations or configure individually
- **Bulk Edit**: Mass enable/disable YoCo backorder

## Usage

### Manual Operations
- **Test Feed**: Validate supplier CSV feeds before configuration
- **Manual Sync**: Trigger immediate synchronization per supplier
- **Stock Check**: Refresh supplier stock for individual products

### Automatic Operations
- **Scheduled Sync**: Configure update frequency (1-24 times per day)
- **Custom Times**: Set specific sync times per supplier
- **Cron Integration**: WordPress cron for automated execution

### Stock Logic
1. **Product has Jokasport stock** → Use default delivery time
2. **No Jokasport stock** → Check supplier availability
3. **Supplier has stock** → Set backorder with supplier delivery time
4. **No supplier stock** → Leave out of stock

## Database Structure

### yoco_supplier_settings
- Supplier configuration per `pa_xcore_suppliers` term
- Feed URLs, mapping, schedules, delivery times

### yoco_supplier_stock
- Cached supplier stock per product
- SKU/EAN matching, stock quantities, availability status

### yoco_sync_logs
- Comprehensive sync history
- Status tracking, error logging, performance metrics

## Technical Details

### Requirements
- WordPress 5.0+
- WooCommerce 5.0+
- PHP 7.4+
- Existing `pa_xcore_suppliers` taxonomy

### Key Classes
- `YoCo_Supplier`: Supplier management and configuration
- `YoCo_Product`: Product-level settings and stock display
- `YoCo_Sync`: Feed processing and synchronization
- `YoCo_Admin`: Admin interface and AJAX handlers

### Product Meta Fields
- `_yoco_backorder_enabled`: Enable/disable YoCo for product
- `_yoco_supplier_stock`: Cached supplier stock data
- `_yoco_last_updated`: Last sync timestamp
- `ean_13`: EAN for product matching (existing field)

### File Structure
```
yoco-backordersystem/
├── yoco-backordersystem.php     # Main plugin file
├── includes/
│   ├── class-yoco-install.php   # Installation & database
│   ├── class-yoco-admin.php     # Admin interface
│   ├── class-yoco-supplier.php  # Supplier management
│   ├── class-yoco-product.php   # Product functionality
│   └── class-yoco-sync.php      # Synchronization
├── templates/admin/
│   ├── dashboard.php            # Main dashboard
│   ├── suppliers.php            # Supplier configuration
│   ├── sync-logs.php           # Sync history
│   └── settings.php            # Global settings
└── assets/
    ├── css/admin.css           # Admin styling
    └── js/admin.js             # Admin JavaScript
```

## Development

### API Endpoints (AJAX)
- `yoco_test_supplier_feed`: Test CSV feed validity
- `yoco_sync_supplier`: Manual supplier synchronization
- `yoco_check_product_stock`: Refresh product stock

### Hooks & Filters
- `yoco_before_init`: Before plugin initialization
- `yoco_init`: After plugin initialization
- `yoco_installed`: After plugin installation
- `yoco_deactivated`: After plugin deactivation

### Extensibility
- Filter hooks for custom delivery time logic
- Action hooks for sync completion events
- Database structure allows custom mapping configurations

## Troubleshooting

### Common Issues
1. **Supplier taxonomy missing**: Ensure `pa_xcore_suppliers` exists
2. **Feed parsing errors**: Check CSV delimiter and format
3. **No products syncing**: Verify YoCo is enabled on products
4. **Sync failures**: Check feed URL accessibility and format

### Debug Mode
Enable in **YoCo Backorder → Settings** for detailed logging.

### Log Analysis
View detailed sync logs in **YoCo Backorder → Sync Logs** with error details and performance metrics.

## Support

For issues specific to Jokasport implementation:
- Check existing supplier configuration in WooCommerce attributes
- Verify product has correct `pa_xcore_suppliers` assignment
- Ensure EAN13 fields are populated for product matching

## Version History

### 1.0.0
- Initial release
- Basic supplier management
- CSV feed processing
- Manual synchronization
- Product-level configuration
- Comprehensive admin interface

## License

GPL v2 or later

## Author

**YourCoding**  
Website: https://yourcoding.nl  
Plugin URI: https://github.com/yourcodingNL/yoco-backorderystem