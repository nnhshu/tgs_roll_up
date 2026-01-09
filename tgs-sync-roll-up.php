<?php
/**
 * Plugin Name: TGS Sync Roll-Up
 * Plugin URI: https://thegioisua.vn
 * Description: Plugin đồng bộ dữ liệu roll-up giữa các shop trong WordPress Multisite. Tự động cào dữ liệu và đẩy lên shop cha.
 * Version: 1.0.0
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
define('TGS_SYNC_ROLL_UP_VERSION', '1.0.3');
define('TGS_SYNC_ROLL_UP_PATH', plugin_dir_path(__FILE__));
define('TGS_SYNC_ROLL_UP_URL', plugin_dir_url(__FILE__));
define('TGS_SYNC_ROLL_UP_BASENAME', plugin_basename(__FILE__));

// Ledger type constants (nếu chưa được định nghĩa bởi plugin tgs_shop_management)
if (!defined('TGS_LEDGER_TYPE_IMPORT')) {
    define('TGS_LEDGER_TYPE_IMPORT', 1);      // Nhập nội bộ
}
if (!defined('TGS_LEDGER_TYPE_EXPORT')) {
    define('TGS_LEDGER_TYPE_EXPORT', 2);      // Xuất nội bộ
}
if (!defined('TGS_LEDGER_TYPE_DAMAGE')) {
    define('TGS_LEDGER_TYPE_DAMAGE', 6);      // Hàng hỏng
}
if (!defined('TGS_LEDGER_TYPE_RECEIPT')) {
    define('TGS_LEDGER_TYPE_RECEIPT', 7);     // Thu tiền
}
if (!defined('TGS_LEDGER_TYPE_PAYMENT')) {
    define('TGS_LEDGER_TYPE_PAYMENT', 8);     // Chi tiền
}
if (!defined('TGS_LEDGER_TYPE_PURCHASE')) {
    define('TGS_LEDGER_TYPE_PURCHASE', 9);    // Mua hàng
}
if (!defined('TGS_LEDGER_TYPE_SALES')) {
    define('TGS_LEDGER_TYPE_SALES', 10);      // Bán hàng
}
if (!defined('TGS_LEDGER_TYPE_RETURN')) {
    define('TGS_LEDGER_TYPE_RETURN', 11);     // Trả hàng
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
        require_once TGS_SYNC_ROLL_UP_PATH . 'includes/class-database.php';
        require_once TGS_SYNC_ROLL_UP_PATH . 'includes/class-data-collector.php';
        require_once TGS_SYNC_ROLL_UP_PATH . 'includes/class-roll-up-calculator.php';
        require_once TGS_SYNC_ROLL_UP_PATH . 'includes/class-sync-manager.php';
        require_once TGS_SYNC_ROLL_UP_PATH . 'includes/class-cron-handler.php';

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

        // Admin menu
        if (is_admin()) {
            add_action('admin_menu', array($this, 'add_admin_menu'));
            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        }

        // Cron hook
        add_action('tgs_sync_rollup_cron', array($this, 'run_cron_sync'));

        // AJAX handlers
        add_action('wp_ajax_tgs_sync_rollup_manual', array($this, 'ajax_manual_sync'));
        add_action('wp_ajax_tgs_sync_rollup_save_settings', array($this, 'ajax_save_settings'));
        add_action('wp_ajax_tgs_sync_rollup_rebuild', array($this, 'ajax_rebuild_rollup'));
    }

    /**
     * Plugin activation
     */
    public function activate()
    {
        // Create config table
        $database = new TGS_Sync_Roll_Up_Database();
        $database->create_tables();

        // Schedule cron
        $cron_handler = new TGS_Cron_Handler();
        $cron_handler->schedule_crons();

        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation
     */
    public function deactivate()
    {
        // Clear scheduled cron
        $cron_handler = new TGS_Cron_Handler();
        $cron_handler->unschedule_crons();

        flush_rewrite_rules();
    }

    /**
     * Initialize
     */
    public function init()
    {
        // Load text domain
        load_plugin_textdomain('tgs-sync-roll-up', false, dirname(TGS_SYNC_ROLL_UP_BASENAME) . '/languages');

        // Initialize admin page
        if (is_admin()) {
            new TGS_Admin_Page();
        }

        // Initialize cron handler
        new TGS_Cron_Handler();
    }

    /**
     * Add custom cron intervals
     */
    public function add_cron_intervals($schedules)
    {
        $schedules['every_15_minutes'] = array(
            'interval' => 900,
            'display'  => __('Every 15 Minutes', 'tgs-sync-roll-up')
        );

        $schedules['every_30_minutes'] = array(
            'interval' => 1800,
            'display'  => __('Every 30 Minutes', 'tgs-sync-roll-up')
        );

        $schedules['every_2_hours'] = array(
            'interval' => 7200,
            'display'  => __('Every 2 Hours', 'tgs-sync-roll-up')
        );

        $schedules['every_6_hours'] = array(
            'interval' => 21600,
            'display'  => __('Every 6 Hours', 'tgs-sync-roll-up')
        );

        $schedules['every_12_hours'] = array(
            'interval' => 43200,
            'display'  => __('Every 12 Hours', 'tgs-sync-roll-up')
        );

        return $schedules;
    }

    /**
     * Add admin menu - Handled by TGS_Admin_Page class
     */
    public function add_admin_menu()
    {
        // Admin menu is now handled by TGS_Admin_Page class
    }

    /**
     * Enqueue admin scripts - Handled by TGS_Admin_Page class
     */
    public function enqueue_admin_scripts($hook)
    {
        // Admin scripts are now handled by TGS_Admin_Page class
    }

    /**
     * Run cron sync
     */
    public function run_cron_sync()
    {
        $cron_handler = new TGS_Cron_Handler();
        $cron_handler->run_sync_cron();
    }

    /**
     * AJAX: Manual sync
     */
    public function ajax_manual_sync()
    {
        check_ajax_referer('tgs_sync_roll_up_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Không có quyền thực hiện', 'tgs-sync-roll-up')));
        }

        $sync_manager = new TGS_Sync_Manager();
        $result = $sync_manager->trigger_sync();

        wp_send_json_success(array(
            'message' => __('Đồng bộ thành công!', 'tgs-sync-roll-up'),
            'result' => $result
        ));
    }

    /**
     * AJAX: Save settings
     */
    public function ajax_save_settings()
    {
        check_ajax_referer('tgs_sync_roll_up_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Không có quyền thực hiện', 'tgs-sync-roll-up')));
        }

        $blog_id = get_current_blog_id();
        $parent_blog_ids = isset($_POST['parent_blog_ids']) ? array_map('intval', (array) $_POST['parent_blog_ids']) : array();
        $sync_frequency = isset($_POST['sync_frequency']) ? sanitize_text_field($_POST['sync_frequency']) : 'hourly';
        $sync_enabled = isset($_POST['sync_enabled']) ? intval($_POST['sync_enabled']) : 1;

        $database = new TGS_Sync_Roll_Up_Database();
        $result = $database->save_config($blog_id, array(
            'parent_blog_ids' => json_encode($parent_blog_ids),
            'sync_frequency' => $sync_frequency,
            'sync_enabled' => $sync_enabled,
        ));

        // Update cron schedule
        $cron_handler = new TGS_Cron_Handler();
        $cron_handler->update_cron_frequency($sync_frequency);

        if ($result !== false) {
            wp_send_json_success(array('message' => __('Đã lưu cài đặt!', 'tgs-sync-roll-up')));
        } else {
            wp_send_json_error(array('message' => __('Có lỗi xảy ra!', 'tgs-sync-roll-up')));
        }
    }

    /**
     * AJAX: Rebuild roll-up
     */
    public function ajax_rebuild_rollup()
    {
        check_ajax_referer('tgs_sync_roll_up_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Không có quyền thực hiện', 'tgs-sync-roll-up')));
        }

        $blog_id = get_current_blog_id();
        $start_date = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : date('Y-m-01');
        $end_date = isset($_POST['end_date']) ? sanitize_text_field($_POST['end_date']) : current_time('Y-m-d');

        $calculator = new TGS_Roll_Up_Calculator();
        $result = $calculator->rebuild_roll_up($blog_id, $start_date, $end_date);

        wp_send_json_success(array(
            'message' => sprintf(
                __('Đã tính lại %d/%d ngày thành công!', 'tgs-sync-roll-up'),
                $result['success'],
                $result['success'] + $result['failed']
            ),
            'data' => $result
        ));
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
