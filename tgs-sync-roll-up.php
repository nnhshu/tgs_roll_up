<?php
/**
 * Plugin Name: TGS Sync Roll-Up
 * Plugin URI: https://thegioisua.vn
 * Description: Plugin đồng bộ dữ liệu roll-up giữa các shop trong WordPress Multisite. Tự động cào dữ liệu và đẩy lên shop cha. v2.0 - Refactored với Clean Architecture.
 * Version: 2.0.0
 * Author: TGS Development Team
 * Author URI: https://thegioisua.vn
 * Text Domain: tgs-sync-roll-up
 * Domain Path: /languages
 * Network: true
 * Requires at least: 5.0
 * Requires PHP: 7.4
 *
 * @package TGS_Sync_Roll_Up
 */

if (!defined('ABSPATH')) {
    exit;
}

// Plugin constants
define('TGS_SYNC_ROLL_UP_VERSION', '2.0.0');
define('TGS_SYNC_ROLL_UP_PATH', plugin_dir_path(__FILE__));
define('TGS_SYNC_ROLL_UP_URL', plugin_dir_url(__FILE__));
define('TGS_SYNC_ROLL_UP_BASENAME', plugin_basename(__FILE__));

// Ledger type constants (nếu chưa được định nghĩa bởi plugin tgs_shop_management)
if (!defined('TGS_LEDGER_TYPE_IMPORT_ROLL_UP')) {
    define('TGS_LEDGER_TYPE_IMPORT_ROLL_UP', 1);      // Nhập hàng
}
if (!defined('TGS_LEDGER_TYPE_EXPORT_ROLL_UP')) {
    define('TGS_LEDGER_TYPE_EXPORT_ROLL_UP', 2);      // Xuất hàng
}
if (!defined('TGS_LEDGER_TYPE_DAMAGE_ROLL_UP')) {
    define('TGS_LEDGER_TYPE_DAMAGE_ROLL_UP', 6);      // Hàng hỏng
}
if (!defined('TGS_LEDGER_TYPE_RECEIPT_ROLL_UP')) {
    define('TGS_LEDGER_TYPE_RECEIPT_ROLL_UP', 7);     // Thu tiền
}
if (!defined('TGS_LEDGER_TYPE_PAYMENT_ROLL_UP')) {
    define('TGS_LEDGER_TYPE_PAYMENT_ROLL_UP', 8);     // Chi tiền
}
if (!defined('TGS_LEDGER_TYPE_PURCHASE_ROLL_UP')) {
    define('TGS_LEDGER_TYPE_PURCHASE_ROLL_UP', 9);    // Mua hàng
}
if (!defined('TGS_LEDGER_TYPE_SALES_ROLL_UP')) {
    define('TGS_LEDGER_TYPE_SALES_ROLL_UP', 10);      // Bán hàng
}
if (!defined('TGS_LEDGER_TYPE_RETURN_ROLL_UP')) {
    define('TGS_LEDGER_TYPE_RETURN_ROLL_UP', 11);     // Trả hàng
}

// Product tag constants
if (!defined('TGS_PRODUCT_TAG_NORMAL')) {
    define('TGS_PRODUCT_TAG_NORMAL', 0);
}
if (!defined('TGS_PRODUCT_TAG_STRATEGIC')) {
    define('TGS_PRODUCT_TAG_STRATEGIC', 1);
}
if (!defined('TGS_PRODUCT_TAG_PROMOTIONAL')) {
    define('TGS_PRODUCT_TAG_PROMOTIONAL', 2);
}
if (!defined('TGS_PRODUCT_TAG_NEW')) {
    define('TGS_PRODUCT_TAG_NEW', 3);
}

if (!defined('TGSR_TABLE_SYNC_ROLL_UP_CONFIG')) {
    define('TGSR_TABLE_SYNC_ROLL_UP_CONFIG', 'wp_sync_roll_up_config');
}

/**
 * Main Plugin Class
 */
class TGS_Sync_Roll_Up
{
    /**
     * Single instance
     */
    private static $instance = null;

    /**
     * Get instance
     */
    public static function get_instance()
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct()
    {
        $this->load_dependencies();
        $this->init_hooks();
    }

    /**
     * Load required files
     */
    private function load_dependencies()
    {
        // Legacy - Only database class for table creation
        require_once TGS_SYNC_ROLL_UP_PATH . 'includes/class-database.php';

        // New Architecture - Core
        require_once TGS_SYNC_ROLL_UP_PATH . 'includes/Core/Interfaces/DataSourceInterface.php';
        require_once TGS_SYNC_ROLL_UP_PATH . 'includes/Core/Interfaces/RollUpRepositoryInterface.php';
        require_once TGS_SYNC_ROLL_UP_PATH . 'includes/Core/Interfaces/ConfigRepositoryInterface.php';
        require_once TGS_SYNC_ROLL_UP_PATH . 'includes/Core/ServiceContainer.php';

        // New Architecture - Infrastructure
        require_once TGS_SYNC_ROLL_UP_PATH . 'includes/Infrastructure/MultiSite/BlogContext.php';
        require_once TGS_SYNC_ROLL_UP_PATH . 'includes/Infrastructure/External/TgsShopDataSource.php';
        require_once TGS_SYNC_ROLL_UP_PATH . 'includes/Infrastructure/Database/Repositories/ProductRollUpRepository.php';
        require_once TGS_SYNC_ROLL_UP_PATH . 'includes/Infrastructure/Database/Repositories/ConfigRepository.php';
        require_once TGS_SYNC_ROLL_UP_PATH . 'includes/Infrastructure/Database/Repositories/InventoryRollUpRepository.php';
        require_once TGS_SYNC_ROLL_UP_PATH . 'includes/Infrastructure/Database/Repositories/OrderRollUpRepository.php';
        require_once TGS_SYNC_ROLL_UP_PATH . 'includes/Infrastructure/Database/Repositories/AccountingRollUpRepository.php';

        // New Architecture - Application
        require_once TGS_SYNC_ROLL_UP_PATH . 'includes/Application/UseCases/CalculateDailyProductRollup.php';
        require_once TGS_SYNC_ROLL_UP_PATH . 'includes/Application/UseCases/CalculateDailyInventory.php';
        require_once TGS_SYNC_ROLL_UP_PATH . 'includes/Application/UseCases/CalculateDailyOrder.php';
        require_once TGS_SYNC_ROLL_UP_PATH . 'includes/Application/UseCases/CalculateDailyAccounting.php';
        require_once TGS_SYNC_ROLL_UP_PATH . 'includes/Application/UseCases/SyncToParentShop.php';
        require_once TGS_SYNC_ROLL_UP_PATH . 'includes/Application/UseCases/SyncInventoryToParentShop.php';
        require_once TGS_SYNC_ROLL_UP_PATH . 'includes/Application/UseCases/SyncOrderToParentShop.php';
        require_once TGS_SYNC_ROLL_UP_PATH . 'includes/Application/UseCases/SyncAccountingToParentShop.php';
        require_once TGS_SYNC_ROLL_UP_PATH . 'includes/Application/Services/CronService.php';

        // New Architecture - Extensions
        require_once TGS_SYNC_ROLL_UP_PATH . 'includes/Extensions/SyncTypeRegistry.php';
        require_once TGS_SYNC_ROLL_UP_PATH . 'includes/Extensions/FilterHooks.php';

        // New Architecture - Presentation
        require_once TGS_SYNC_ROLL_UP_PATH . 'includes/Presentation/Ajax/SyncAjaxHandler.php';
        require_once TGS_SYNC_ROLL_UP_PATH . 'includes/Presentation/Ajax/ConfigAjaxHandler.php';
        require_once TGS_SYNC_ROLL_UP_PATH . 'includes/Presentation/Ajax/DashboardAjaxHandler.php';

        // Register services BEFORE loading admin page
        ServiceContainer::registerServices();

        // Initialize filter hooks
        FilterHooks::init();

        if (is_admin()) {
            require_once TGS_SYNC_ROLL_UP_PATH . 'includes/class-admin-page.php';
        }
    }

    /**
     * Initialize hooks
     */
    private function init_hooks()
    {
        // Activation/Deactivation
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));

        // Init
        add_action('init', array($this, 'init'));

        // Cron hook
        add_action('tgs_sync_rollup_cron', array($this, 'run_cron_sync'));
    }

    /**
     * Plugin activation
     */
    public function activate()
    {
        // Create config table
        $database = new TGS_Sync_Roll_Up_Database();
        $database->create_tables();

        // Create inventory_roll_up table
        $this->create_inventory_table();

        // Schedule cron using new CronService
        try {
            $cronService = ServiceContainer::make(CronService::class);
            $cronService->scheduleCrons();
        } catch (Exception $e) {
            error_log('TGS Sync: Failed to schedule crons - ' . $e->getMessage());
        }

        flush_rewrite_rules();
    }

    /**
     * Create inventory_roll_up table
     */
    private function create_inventory_table()
    {
        global $wpdb;

        $table_name = $wpdb->prefix . 'inventory_roll_up';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            `id` bigint NOT NULL AUTO_INCREMENT,
            `blog_id` bigint DEFAULT NULL,
            `local_product_name_id` bigint NOT NULL,
            `global_product_name_id` bigint DEFAULT NULL,
            `roll_up_date` date NOT NULL,
            `roll_up_day` int NOT NULL,
            `roll_up_month` int NOT NULL,
            `roll_up_year` int NOT NULL,
            `in_qty` decimal(15,3) DEFAULT '0.000',
            `in_value` decimal(15,2) DEFAULT '0.00',
            `out_qty` decimal(15,3) DEFAULT '0.000',
            `out_value` decimal(15,2) DEFAULT '0.00',
            `end_qty` decimal(15,3) DEFAULT '0.000',
            `end_value` decimal(15,2) DEFAULT '0.00',
            `daily_cogs_value` decimal(15,2) DEFAULT '0.00',
            `meta` json DEFAULT NULL,
            `created_at` datetime NOT NULL,
            `updated_at` datetime DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_blog_day_month_year_product` (`blog_id`,`roll_up_day`,`roll_up_month`,`roll_up_year`,`local_product_name_id`),
            KEY `idx_blog_id` (`blog_id`),
            KEY `idx_roll_up_date` (`roll_up_date`),
            KEY `idx_product_name` (`local_product_name_id`)
        ) {$charset_collate};";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }

    /**
     * Plugin deactivation
     */
    public function deactivate()
    {
        // Clear scheduled cron using new CronService
        try {
            $cronService = ServiceContainer::make(CronService::class);
            $cronService->unscheduleCrons();
        } catch (Exception $e) {
            error_log('TGS Sync: Failed to unschedule crons - ' . $e->getMessage());
        }

        flush_rewrite_rules();
    }

    /**
     * Initialize
     */
    public function init()
    {
        // Load text domain
        load_plugin_textdomain('tgs-sync-roll-up', false, dirname(TGS_SYNC_ROLL_UP_BASENAME) . '/languages');

        // Initialize admin page (legacy - keeping for UI)
        if (is_admin()) {
            new TGS_Admin_Page();

            // Register new AJAX handlers
            try {
                $syncHandler = ServiceContainer::make(SyncAjaxHandler::class);
                $syncHandler->registerHooks();

                $configHandler = ServiceContainer::make(ConfigAjaxHandler::class);
                $configHandler->registerHooks();

                $dashboardHandler = ServiceContainer::make(DashboardAjaxHandler::class);
                $dashboardHandler->registerHooks();
            } catch (Exception $e) {
                error_log('TGS Sync Roll-Up: Failed to initialize AJAX handlers - ' . $e->getMessage());
            }
        }
    }


    /**
     * Run cron sync using new CronService
     */
    public function run_cron_sync()
    {
        try {
            $cronService = ServiceContainer::make(CronService::class);
            $cronService->runSyncCron();
        } catch (Exception $e) {
            error_log('TGS Sync: Cron failed - ' . $e->getMessage());
        }
    }
}

// Initialize plugin
function tgs_sync_roll_up_init()
{
    // Check if tgs_shop_management is active
    if (!class_exists('TGS_Shop_Constants')) {
        add_action('admin_notices', function () {
            echo '<div class="notice notice-error"><p>';
            echo esc_html__('TGS Sync Roll-Up yêu cầu plugin TGS Shop Management phải được kích hoạt.', 'tgs-sync-roll-up');
            echo '</p></div>';
        });
        return;
    }

    return TGS_Sync_Roll_Up::get_instance();
}

add_action('plugins_loaded', 'tgs_sync_roll_up_init', 20);
