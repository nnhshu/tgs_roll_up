<?php
/**
 * Admin Page Class
 * Quản lý trang admin cho plugin TGS Sync Roll Up
 *
 * @package TGS_Sync_Roll_Up
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Admin_Page
{
    /**
     * Config repository
     */
    private $configRepo;

    /**
     * Roll-up repository
     */
    private $rollUpRepo;

    /**
     * Cron service
     */
    private $cronService;

    /**
     * Sync use case
     */
    private $syncUseCase;

    /**
     * Calculate use case
     */
    private $calculateUseCase;

    /**
     * Calculate inventory use case
     */
    private $calculateInventory;

    /**
     * Database wrapper (backward compatibility)
     */
    private $database;

    /**
     * Sync manager wrapper (backward compatibility)
     */
    private $sync_manager;

    /**
     * Cron handler wrapper (backward compatibility)
     */
    private $cron_handler;

    /**
     * Calculator wrapper (backward compatibility)
     */
    private $calculator;

    /**
     * Menu slug
     */
    const MENU_SLUG = 'tgs-sync-roll-up';

    /**
     * Constructor
     */
    public function __construct()
    {
        // Use ServiceContainer to get dependencies
        try {
            $this->configRepo = ServiceContainer::make(ConfigRepositoryInterface::class);
            $this->rollUpRepo = ServiceContainer::make(RollUpRepositoryInterface::class);
            $this->cronService = ServiceContainer::make(CronService::class);
            $this->syncUseCase = ServiceContainer::make(SyncToParentShop::class);
            $this->calculateUseCase = ServiceContainer::make(CalculateDailyProductRollup::class);
            $this->calculateInventory = ServiceContainer::make(CalculateDailyInventory::class);
        } catch (Exception $e) {
            error_log('TGS Admin: Failed to initialize dependencies - ' . $e->getMessage());
        }

        // Create wrapper objects for backward compatibility
        $this->database = $this->createDatabaseWrapper();
        $this->sync_manager = $this->createSyncManagerWrapper();
        $this->cron_handler = $this->createCronHandlerWrapper();
        $this->calculator = $this->createCalculatorWrapper();

        // Hooks
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));

        // AJAX handlers
        add_action('wp_ajax_tgs_save_parent_shop', array($this, 'ajax_save_parent_shop'));
        add_action('wp_ajax_tgs_cancel_parent_request', array($this, 'ajax_cancel_parent_request'));
        add_action('wp_ajax_tgs_approve_parent_request', array($this, 'ajax_approve_parent_request'));
        add_action('wp_ajax_tgs_reject_parent_request', array($this, 'ajax_reject_parent_request'));
        add_action('wp_ajax_tgs_save_sync_settings', array($this, 'ajax_save_settings'));
        // add_action('wp_ajax_tgs_manual_sync', array($this, 'ajax_manual_sync'));
        // add_action('wp_ajax_tgs_rebuild_rollup', array($this, 'ajax_rebuild_rollup'));
        add_action('wp_ajax_tgs_get_sync_status', array($this, 'ajax_get_sync_status'));
        add_action('wp_ajax_tgs_get_dashboard_data', array($this, 'ajax_get_dashboard_data'));
        add_action('wp_ajax_tgs_get_stats_by_date', array($this, 'ajax_get_stats_by_date'));
        add_action('wp_ajax_tgs_get_child_shop_detail', array($this, 'ajax_get_child_shop_detail'));
    }

    /**
     * Thêm menu admin
     */
    public function add_admin_menu()
    {
        add_menu_page(
            __('TGS Sync Roll Up', 'tgs-sync-roll-up'),
            __('TGS Sync', 'tgs-sync-roll-up'),
            'manage_options',
            self::MENU_SLUG,
            array($this, 'render_dashboard_page'),
            'dashicons-update',
            80
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('Dashboard', 'tgs-sync-roll-up'),
            __('Dashboard', 'tgs-sync-roll-up'),
            'manage_options',
            self::MENU_SLUG,
            array($this, 'render_dashboard_page')
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('Settings', 'tgs-sync-roll-up'),
            __('Settings', 'tgs-sync-roll-up'),
            'manage_options',
            self::MENU_SLUG . '-settings',
            array($this, 'render_settings_page')
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('Logs', 'tgs-sync-roll-up'),
            __('Logs', 'tgs-sync-roll-up'),
            'manage_options',
            self::MENU_SLUG . '-logs',
            array($this, 'render_logs_page')
        );

        add_submenu_page(
            self::MENU_SLUG,
            __('Inventory', 'tgs-sync-roll-up'),
            __('Inventory', 'tgs-sync-roll-up'),
            'manage_options',
            self::MENU_SLUG . '-inventory',
            array($this, 'render_inventory_page')
        );
    }

    /**
     * Enqueue admin assets
     *
     * @param string $hook Hook name
     */
    public function enqueue_admin_assets($hook)
    {
        // Chỉ load trên trang của plugin
        if (strpos($hook, self::MENU_SLUG) === false) {
            return;
        }

        // CSS
        wp_enqueue_style(
            'tgs-sync-roll-up-admin',
            TGS_SYNC_ROLL_UP_URL . 'admin/css/admin.css',
            array(),
            TGS_SYNC_ROLL_UP_VERSION
        );

        // JS
        wp_enqueue_script(
            'tgs-sync-roll-up-admin',
            TGS_SYNC_ROLL_UP_URL . 'admin/js/admin.js',
            array('jquery'),
            TGS_SYNC_ROLL_UP_VERSION,
            true
        );

        // Lấy thông tin hierarchy của tất cả shops
        $shop_hierarchy = $this->get_shop_hierarchy();

        // Localize script
        wp_localize_script('tgs-sync-roll-up-admin', 'tgsSyncRollUp', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('tgs_sync_roll_up_nonce'),
            'currentBlogId' => get_current_blog_id(),
            'shopHierarchy' => $shop_hierarchy,
            'i18n' => array(
                'saving' => __('Saving...', 'tgs-sync-roll-up'),
                'saved' => __('Settings saved!', 'tgs-sync-roll-up'),
                'error' => __('An error occurred', 'tgs-sync-roll-up'),
                'syncing' => __('Syncing...', 'tgs-sync-roll-up'),
                'syncComplete' => __('Sync complete!', 'tgs-sync-roll-up'),
                'rebuilding' => __('Rebuilding...', 'tgs-sync-roll-up'),
                'rebuildComplete' => __('Rebuild complete!', 'tgs-sync-roll-up'),
                'confirmRebuild' => __('Are you sure you want to rebuild roll_up data? This may take a while.', 'tgs-sync-roll-up'),
                'parentDisabledReason' => __('Shop này đã là cha của shop cha khác. Dữ liệu sẽ tự động đẩy lên qua trung gian.', 'tgs-sync-roll-up'),
            ),
        ));

        // Chart.js for dashboard
        if ($hook === 'toplevel_page_' . self::MENU_SLUG) {
            wp_enqueue_script(
                'chartjs',
                'https://cdn.jsdelivr.net/npm/chart.js',
                array(),
                '4.4.1',
                true
            );
        }
    }

    /**
     * Render dashboard page
     */
    public function render_dashboard_page()
    {
        $blog_id = get_current_blog_id();

        // Đảm bảo các bảng tồn tại trước khi thực hiện các thao tác khác
        $this->database->ensure_tables_exist();

        $config = $this->database->get_config($blog_id);
        $sync_status = $this->database->get_sync_status($blog_id);
        $cron_info = $this->cron_handler->get_next_scheduled();
        $recent_logs = $this->sync_manager->get_recent_sync_logs($blog_id, 5);

        // Lấy dữ liệu roll_up
        $today = current_time('Y-m-d');

        // Tính tổng doanh thu hôm nay từ bảng roll_up
        $today_total_revenue = $this->calculator->get_total_revenue_sum($blog_id, $today, $today);

        // Lấy danh sách blogs để hiển thị tên shop con
        $all_blogs = $this->database->get_all_blogs();

        // Lấy roll_up 7 ngày gần đây cho biểu đồ
        $chart_data = $this->get_chart_data($blog_id, 7);

        // Tìm các shop con đang cấu hình sync lên shop này
        $shops_syncing_to_me = $this->get_child_shops($blog_id);

        // Lấy danh sách yêu cầu đang chờ approve
        $pending_requests = $this->get_pending_child_requests($blog_id);

        include TGS_SYNC_ROLL_UP_PATH . 'admin/views/dashboard.php';
    }

    /**
     * Render settings page
     */
    public function render_settings_page()
    {
        $blog_id = get_current_blog_id();
        $config = $this->database->get_config($blog_id);
        $all_blogs = $this->database->get_all_blogs();
        $cron_info = $this->cron_handler->get_next_scheduled();

        // Lấy shop cha đã cấu hình (single parent)
        $parent_blog_id = null;
        if ($config && !empty($config->parent_blog_id)) {
            $parent_blog_id = intval($config->parent_blog_id);
        }

        // Lấy hierarchy để hiển thị cây phân cấp
        $shop_hierarchy = $this->get_shop_hierarchy();

        // Tạo map blog_id => blog_name để hiển thị
        $blog_names = array();
        foreach ($all_blogs as $blog) {
            $blog_names[intval($blog->blog_id)] = self::get_blog_name($blog->blog_id);
        }

        // Xây dựng cây phân cấp (tìm root nodes và children)
        $hierarchy_tree = $this->build_hierarchy_tree($shop_hierarchy, $blog_names);

        include TGS_SYNC_ROLL_UP_PATH . 'admin/views/settings-page.php';
    }

    /**
     * Xây dựng cây phân cấp từ hierarchy data
     *
     * @param array $hierarchy Map blog_id => parent_id (single parent)
     * @param array $blog_names Map blog_id => blog_name
     * @return array Hierarchy tree structure
     */
    private function build_hierarchy_tree($hierarchy, $blog_names)
    {
        // Tìm children của mỗi node (đảo ngược quan hệ parent)
        $children = array();
        $all_children = array(); // Tất cả blog_ids đã là con của ai đó

        foreach ($hierarchy as $blog_id => $parent_id) {
            if (!empty($parent_id)) {
                if (!isset($children[$parent_id])) {
                    $children[$parent_id] = array();
                }
                if (!in_array($blog_id, $children[$parent_id])) {
                    $children[$parent_id][] = $blog_id;
                }
                $all_children[] = $blog_id;
            }
        }
        $all_children = array_unique($all_children);

        // Root nodes = blogs không có cha (parent_id null/empty)
        $root_nodes = array();
        foreach ($hierarchy as $blog_id => $parent_id) {
            if (empty($parent_id)) {
                $root_nodes[] = $blog_id;
            }
        }

        // Đảm bảo root_nodes không chứa blogs đã là con của ai
        $root_nodes = array_values(array_diff($root_nodes, $all_children));

        // Nếu không có root node, có thể có cycle
        // Fallback: lấy blogs có cha không tồn tại trong hierarchy
        if (empty($root_nodes)) {
            foreach ($hierarchy as $blog_id => $parent_id) {
                if (!empty($parent_id) && !isset($hierarchy[$parent_id])) {
                    $root_nodes[] = $blog_id;
                }
            }
        }

        // Final fallback: lấy tất cả blogs không là con của ai
        if (empty($root_nodes)) {
            $root_nodes = array_values(array_diff(array_keys($hierarchy), $all_children));
        }

        // Ultimate fallback
        if (empty($root_nodes)) {
            $root_nodes = array_keys($hierarchy);
        }

        return array(
            'hierarchy' => $hierarchy,
            'children' => $children,
            'root_nodes' => $root_nodes,
            'blog_names' => $blog_names,
        );
    }

    /**
     * Render logs page
     */
    public function render_logs_page()
    {
        $blog_id = get_current_blog_id();
        $sync_logs = $this->sync_manager->get_recent_sync_logs($blog_id, 50);
        $cron_logs = $this->cron_handler->get_recent_cron_logs(50);

        include TGS_SYNC_ROLL_UP_PATH . 'admin/views/logs-page.php';
    }

    /**
     * AJAX: Save parent shop (gửi yêu cầu với trạng thái pending)
     */
    public function ajax_save_parent_shop()
    {
        check_ajax_referer('tgs_sync_roll_up_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }

        $blog_id = get_current_blog_id();

        // Lấy parent_blog_id từ request
        $parent_blog_id = isset($_POST['parent_blog_id']) && !empty($_POST['parent_blog_id'])
            ? intval($_POST['parent_blog_id'])
            : null;

        // Validate: phải có parent_blog_id
        if (empty($parent_blog_id)) {
            wp_send_json_error(array(
                'message' => __('Vui lòng chọn shop cha!', 'tgs-sync-roll-up'),
            ));
        }

        // Validate: không cho phép chọn chính mình làm cha
        if ($parent_blog_id == $blog_id) {
            wp_send_json_error(array(
                'message' => __('Không thể chọn chính mình làm shop cha!', 'tgs-sync-roll-up'),
            ));
        }

        // Check if parent already configured and approved
        $current_config = $this->database->get_config($blog_id);
        if (!empty($current_config->parent_blog_id) && $current_config->approval_status === 'approved') {
            wp_send_json_error(array(
                'message' => __('Shop cha đã được cấu hình và không thể thay đổi!', 'tgs-sync-roll-up'),
            ));
        }

        // Lưu parent shop với trạng thái pending
        $config_data = array(
            'parent_blog_id' => $parent_blog_id,
            'approval_status' => 'pending',
        );

        $result = $this->database->save_config($config_data, $blog_id);

        if ($result !== false) {
            wp_send_json_success(array(
                'message' => __('Yêu cầu đã được gửi! Đang chờ shop cha xác nhận.', 'tgs-sync-roll-up'),
            ));
        } else {
            wp_send_json_error(array(
                'message' => __('Lỗi khi gửi yêu cầu', 'tgs-sync-roll-up'),
            ));
        }
    }

    /**
     * AJAX: Cancel parent request (hủy yêu cầu)
     */
    public function ajax_cancel_parent_request()
    {
        check_ajax_referer('tgs_sync_roll_up_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }

        $blog_id = get_current_blog_id();

        // Lấy config hiện tại
        $current_config = $this->database->get_config($blog_id);

        // Validate: chỉ có thể hủy khi trạng thái là pending
        if (empty($current_config->approval_status) || $current_config->approval_status !== 'pending') {
            wp_send_json_error(array(
                'message' => __('Không có yêu cầu nào đang chờ để hủy!', 'tgs-sync-roll-up'),
            ));
        }

        // Xóa parent_blog_id và approval_status
        $config_data = array(
            'parent_blog_id' => null,
            'approval_status' => null,
        );

        $result = $this->database->save_config($config_data, $blog_id);

        if ($result !== false) {
            wp_send_json_success(array(
                'message' => __('Yêu cầu đã được hủy thành công!', 'tgs-sync-roll-up'),
            ));
        } else {
            wp_send_json_error(array(
                'message' => __('Lỗi khi hủy yêu cầu', 'tgs-sync-roll-up'),
            ));
        }
    }

    /**
     * AJAX: Approve parent request (shop cha approve yêu cầu)
     */
    public function ajax_approve_parent_request()
    {
        check_ajax_referer('tgs_sync_roll_up_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }

        $child_blog_id = isset($_POST['child_blog_id']) ? intval($_POST['child_blog_id']) : 0;

        if (!$child_blog_id) {
            wp_send_json_error(array(
                'message' => __('ID shop con không hợp lệ!', 'tgs-sync-roll-up'),
            ));
        }

        $parent_blog_id = get_current_blog_id();

        // Lấy config của shop con
        $child_config = $this->database->get_config($child_blog_id);

        // Validate: shop con phải có yêu cầu pending với shop cha này
        if (empty($child_config->approval_status) ||
            $child_config->approval_status !== 'pending' ||
            $child_config->parent_blog_id != $parent_blog_id) {
            wp_send_json_error(array(
                'message' => __('Yêu cầu không hợp lệ hoặc đã hết hạn!', 'tgs-sync-roll-up'),
            ));
        }

        // Cập nhật trạng thái thành approved
        $config_data = array(
            'approval_status' => 'approved',
        );

        $result = $this->database->save_config($config_data, $child_blog_id);

        if ($result !== false) {
            wp_send_json_success(array(
                'message' => __('Đã chấp nhận yêu cầu từ shop con!', 'tgs-sync-roll-up'),
            ));
        } else {
            wp_send_json_error(array(
                'message' => __('Lỗi khi cập nhật trạng thái', 'tgs-sync-roll-up'),
            ));
        }
    }

    /**
     * AJAX: Reject parent request (shop cha từ chối yêu cầu)
     */
    public function ajax_reject_parent_request()
    {
        check_ajax_referer('tgs_sync_roll_up_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }

        $child_blog_id = isset($_POST['child_blog_id']) ? intval($_POST['child_blog_id']) : 0;

        if (!$child_blog_id) {
            wp_send_json_error(array(
                'message' => __('ID shop con không hợp lệ!', 'tgs-sync-roll-up'),
            ));
        }

        $parent_blog_id = get_current_blog_id();

        // Lấy config của shop con
        $child_config = $this->database->get_config($child_blog_id);

        // Validate: shop con phải có yêu cầu pending với shop cha này
        if (empty($child_config->approval_status) ||
            $child_config->approval_status !== 'pending' ||
            $child_config->parent_blog_id != $parent_blog_id) {
            wp_send_json_error(array(
                'message' => __('Yêu cầu không hợp lệ hoặc đã hết hạn!', 'tgs-sync-roll-up'),
            ));
        }

        // Xóa parent_blog_id và set trạng thái rejected
        $config_data = array(
            'parent_blog_id' => null,
            'approval_status' => 'rejected',
        );

        $result = $this->database->save_config($config_data, $child_blog_id);

        if ($result !== false) {
            wp_send_json_success(array(
                'message' => __('Đã từ chối yêu cầu từ shop con!', 'tgs-sync-roll-up'),
            ));
        } else {
            wp_send_json_error(array(
                'message' => __('Lỗi khi cập nhật trạng thái', 'tgs-sync-roll-up'),
            ));
        }
    }

    /**
     * AJAX: Save settings (KHÔNG bao gồm parent shop)
     */
    public function ajax_save_settings()
    {
        check_ajax_referer('tgs_sync_roll_up_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }

        $blog_id = get_current_blog_id();

        // Lấy dữ liệu từ request (KHÔNG lấy parent_blog_id nữa)
        $sync_enabled = isset($_POST['sync_enabled']) ? intval($_POST['sync_enabled']) : 0;
        $sync_frequency = isset($_POST['sync_frequency']) ? sanitize_text_field($_POST['sync_frequency']) : 'hourly';

        // Lưu config sync settings only
        $config_data = array(
            'sync_enabled' => $sync_enabled,
            'sync_interval' => $sync_frequency,
        );

        $result = $this->database->save_config($config_data, $blog_id);

        // $result có thể là false (lỗi) hoặc số rows affected (có thể là 0 nếu không có thay đổi)
        // Coi như success nếu không phải false
        if ($result !== false) {
            // Cập nhật cron frequency nếu thay đổi
            $this->cron_handler->update_cron_frequency($sync_frequency);

            wp_send_json_success(array(
                'message' => __('Cài đặt đã được lưu thành công!', 'tgs-sync-roll-up'),
            ));
        } else {
            wp_send_json_error(array(
                'message' => __('Lỗi khi lưu cài đặt', 'tgs-sync-roll-up'),
            ));
        }
    }

    /**
     * AJAX: Manual sync
     */
    public function ajax_manual_sync()
    {
        check_ajax_referer('tgs_sync_roll_up_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }

        $blog_id = get_current_blog_id();
        $date = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : current_time('Y-m-d');
        $sync_type = isset($_POST['sync_type']) ? sanitize_text_field($_POST['sync_type']) : 'all';

        try {
            $result = $this->cron_handler->sync_specific_date($blog_id, $date, $sync_type);

            $message = __('Sync completed!', 'tgs-sync-roll-up');

            // Tùy chỉnh thông báo dựa trên loại sync
            switch ($sync_type) {
                case 'products':
                    $message = __('Đồng bộ sản phẩm hoàn tất!', 'tgs-sync-roll-up');
                    break;
                case 'orders':
                    $message = __('Đồng bộ đơn hàng hoàn tất!', 'tgs-sync-roll-up');
                    break;
                case 'inventory':
                    $message = __('Đồng bộ tồn kho hoàn tất!', 'tgs-sync-roll-up');
                    break;
                default:
                    $message = __('Đồng bộ tất cả hoàn tất!', 'tgs-sync-roll-up');
                    break;
            }

            wp_send_json_success(array(
                'message' => $message,
                'result' => $result,
            ));
        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => $e->getMessage(),
            ));
        }
    }

    /**
     * AJAX: Rebuild roll_up
     */
    public function ajax_rebuild_rollup()
    {
        check_ajax_referer('tgs_sync_roll_up_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }

        $blog_id = get_current_blog_id();
        $start_date = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : date('Y-m-01');
        $end_date = isset($_POST['end_date']) ? sanitize_text_field($_POST['end_date']) : current_time('Y-m-d');
        $sync_to_parents = isset($_POST['sync_to_parents']) ? (bool) $_POST['sync_to_parents'] : true;

        try {
            $result = $this->cron_handler->rebuild_date_range($blog_id, $start_date, $end_date, $sync_to_parents);

            wp_send_json_success(array(
                'message' => sprintf(
                    __('Rebuild completed! %d/%d days processed.', 'tgs-sync-roll-up'),
                    $result['success'],
                    $result['total']
                ),
                'result' => $result,
            ));
        } catch (Exception $e) {
            wp_send_json_error(array(
                'message' => $e->getMessage(),
            ));
        }
    }

    /**
     * AJAX: Get sync status
     */
    public function ajax_get_sync_status()
    {
        check_ajax_referer('tgs_sync_roll_up_nonce', 'nonce');

        $blog_id = get_current_blog_id();
        $status = $this->database->get_sync_status($blog_id);
        $cron_info = $this->cron_handler->get_next_scheduled();

        wp_send_json_success(array(
            'status' => $status,
            'cron' => $cron_info,
        ));
    }

    /**
     * AJAX: Get dashboard data
     */
    public function ajax_get_dashboard_data()
    {
        check_ajax_referer('tgs_sync_roll_up_nonce', 'nonce');

        $blog_id = get_current_blog_id();
        $days = isset($_GET['days']) ? intval($_GET['days']) : 7;

        $chart_data = $this->get_chart_data($blog_id, $days);
        $today_roll_up = $this->calculator->get_roll_up($blog_id, current_time('Y-m-d'));

        wp_send_json_success(array(
            'chart_data' => $chart_data,
            'today' => $today_roll_up,
        ));
    }

    /**
     * AJAX: Get stats by date range
     */
    public function ajax_get_stats_by_date()
    {
        check_ajax_referer('tgs_sync_roll_up_nonce', 'nonce');

        $blog_id = get_current_blog_id();
        $from_date = isset($_POST['from_date']) ? sanitize_text_field($_POST['from_date']) : current_time('Y-m-d');
        $to_date = isset($_POST['to_date']) ? sanitize_text_field($_POST['to_date']) : current_time('Y-m-d');

        // Validate dates
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from_date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to_date)) {
            wp_send_json_error('Invalid date format');
        }

        // Get aggregated stats
        $stats = $this->calculator->get_stats_by_date_range($blog_id, $from_date, $to_date);

        if ($stats) {
            wp_send_json_success($stats);
        } else {
            // Return empty stats
            wp_send_json_success(array(
                'revenue_total' => 0,
                'revenue_strategic_products' => 0,
                'revenue_normal_products' => 0,
                'count_sales_orders' => 0,
                'count_purchase_orders' => 0,
                'count_return_orders' => 0,
                'count_internal_import' => 0,
                'count_internal_export' => 0,
                'inventory_total_quantity' => 0,
                'inventory_total_value' => 0,
                'count_new_customers' => 0,
                'count_returning_customers' => 0,
                'count_total_customers' => 0,
                'avg_order_value' => 0,
            ));
        }
    }

    /**
     * AJAX: Get child shop detail
     * Lấy chi tiết thống kê của một shop con (bao gồm cả children_summary của nó nếu có)
     */
    public function ajax_get_child_shop_detail()
    {
        check_ajax_referer('tgs_sync_roll_up_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => 'Permission denied'));
        }

        $child_blog_id = isset($_POST['child_blog_id']) ? intval($_POST['child_blog_id']) : 0;
        $date = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : current_time('Y-m-d');

        if (!$child_blog_id) {
            wp_send_json_error(array('message' => 'Invalid blog ID'));
        }

        // Validate date
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            wp_send_json_error(array('message' => 'Invalid date format'));
        }

        // Lấy thông tin blog
        $blog_details = get_blog_details($child_blog_id);
        if (!$blog_details) {
            wp_send_json_error(array('message' => 'Blog not found'));
        }

        // Lấy tổng revenue của shop con từ bảng roll_up
        switch_to_blog($child_blog_id);
        $total_revenue = $this->calculator->get_total_revenue_sum($child_blog_id, $date, $date);
        restore_current_blog();

        // Lấy URL dashboard của shop con
        $child_admin_url = get_admin_url($child_blog_id, 'admin.php?page=tgs-sync-roll-up');

        // Lấy các shop con của shop này (nếu có)
        $child_shops = $this->get_child_shops($child_blog_id);

        wp_send_json_success(array(
            'blog_id' => $child_blog_id,
            'shop_name' => $blog_details->blogname ?: $blog_details->domain . $blog_details->path,
            'admin_url' => $child_admin_url,
            'date' => $date,
            'total_revenue' => $total_revenue,
            'child_shops' => $child_shops,
            'has_children' => !empty($child_shops),
        ));
    }

    /**
     * Lấy dữ liệu cho biểu đồ
     *
     * @param int $blog_id Blog ID
     * @param int $days Số ngày
     * @return array Chart data
     */
    private function get_chart_data($blog_id, $days)
    {
        global $wpdb;

        // Ensure tables exist before querying
        $this->database->get_config($blog_id);

        $table = $wpdb->prefix . 'product_roll_up';
        $end_date = current_time('Y-m-d');
        $start_date = date('Y-m-d', strtotime("-{$days} days"));

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT
                roll_up_date,
                COALESCE(SUM(CASE WHEN type = %d THEN amount_after_tax ELSE 0 END), 0) -
                COALESCE(SUM(CASE WHEN type = %d THEN amount_after_tax ELSE 0 END), 0) as total_revenue
             FROM {$table}
             WHERE blog_id = %d
             AND roll_up_date BETWEEN %s AND %s
             GROUP BY roll_up_date
             ORDER BY roll_up_date ASC",
            TGS_LEDGER_TYPE_SALES_ROLL_UP,
            TGS_LEDGER_TYPE_RETURN_ROLL_UP,
            $blog_id,
            $start_date,
            $end_date
        ));

        $labels = array();
        $revenue = array();

        // Tạo mảng đầy đủ các ngày
        $current = strtotime($start_date);
        $end = strtotime($end_date);
        $data_by_date = array();

        foreach ($results as $row) {
            $data_by_date[$row->roll_up_date] = $row;
        }

        while ($current <= $end) {
            $date = date('Y-m-d', $current);
            $labels[] = date('d/m', $current);

            if (isset($data_by_date[$date])) {
                $revenue[] = floatval($data_by_date[$date]->total_revenue);
            } else {
                $revenue[] = 0;
            }

            $current = strtotime('+1 day', $current);
        }

        return array(
            'labels' => $labels,
            'datasets' => array(
                'revenue' => $revenue,
            ),
            'totals' => array(
                'revenue' => array_sum($revenue),
            ),
        );
    }

    /**
     * Format số tiền
     *
     * @param float $amount Số tiền
     * @return string Số tiền đã format
     */
    public static function format_currency($amount)
    {
        return number_format($amount, 0, ',', '.') . ' đ';
    }

    /**
     * Format số
     *
     * @param float $number Số
     * @return string Số đã format
     */
    public static function format_number($number)
    {
        return number_format($number, 0, ',', '.');
    }

    /**
     * Format thời gian
     *
     * @param string $datetime Datetime string
     * @return string Thời gian đã format
     */
    public static function format_datetime($datetime)
    {
        if (empty($datetime)) {
            return '-';
        }
        return date_i18n('d/m/Y H:i:s', strtotime($datetime));
    }

    /**
     * Lấy thông tin hierarchy của tất cả shops
     * Bao gồm:
     * - Shops có approval_status = 'approved' (đã có cha)
     * - Shops không có parent_blog_id (root shops)
     * Trả về array: blog_id => parent_id (single parent)
     *
     * @return array Shop hierarchy
     */
    private function get_shop_hierarchy()
    {
        global $wpdb;

        $hierarchy = array();

        // Build hierarchy using all blogs and left join config table so that
        // blogs without a config row are included as root nodes.
        $config_table = TGSR_TABLE_SYNC_ROLL_UP_CONFIG;
        $blogs_table = $wpdb->blogs;

        // Use NULLIF to convert empty-string parent_blog_id to NULL
        $sql = "SELECT b.blog_id, NULLIF(c.parent_blog_id, '') AS parent_blog_id
                FROM {$blogs_table} b
                LEFT JOIN {$config_table} c
                  ON c.blog_id = b.blog_id AND c.approval_status = 'approved'";

        $rows = $wpdb->get_results($sql);

        foreach ($rows as $row) {
            $blog_id = intval($row->blog_id);
            $parent_id = isset($row->parent_blog_id) && $row->parent_blog_id !== null && $row->parent_blog_id !== ''
                ? intval($row->parent_blog_id)
                : null;
            $hierarchy[$blog_id] = $parent_id;
        }

        return $hierarchy;
    }

    /**
     * Lấy danh sách các shop con đang cấu hình sync lên shop cha
     * Chỉ lấy những shop đã được approved
     *
     * @param int $parent_blog_id Blog ID của shop cha
     * @return array Mảng chứa thông tin các shop con
     */
    private function get_child_shops($parent_blog_id)
    {
        global $wpdb;

        $shops_syncing_to_me = array();

        // Đọc từ bảng config chung
        // CHỈ LẤY những shop con có approval_status = 'approved'
        $config_table = TGSR_TABLE_SYNC_ROLL_UP_CONFIG;
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT blog_id FROM {$config_table}
             WHERE parent_blog_id = %d
             AND approval_status = 'approved'",
            $parent_blog_id
        ));

        foreach ($results as $row) {
            $shops_syncing_to_me[] = array(
                'blog_id' => $row->blog_id,
                'blog_name' => self::get_blog_name($row->blog_id)
            );
        }

        return $shops_syncing_to_me;
    }

    /**
     * Lấy danh sách các yêu cầu từ shop con đang chờ approve
     *
     * @param int $parent_blog_id Blog ID của shop cha
     * @return array Mảng chứa thông tin các yêu cầu đang chờ
     */
    private function get_pending_child_requests($parent_blog_id)
    {
        global $wpdb;

        $pending_requests = array();

        // Đọc từ bảng config chung
        // LẤY những shop con có approval_status = 'pending'
        $config_table = TGSR_TABLE_SYNC_ROLL_UP_CONFIG;
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT blog_id, created_at, updated_at FROM {$config_table}
             WHERE parent_blog_id = %d
             AND approval_status = 'pending'
             ORDER BY updated_at DESC",
            $parent_blog_id
        ));

        foreach ($results as $row) {
            $pending_requests[] = array(
                'blog_id' => $row->blog_id,
                'blog_name' => self::get_blog_name($row->blog_id),
                'requested_at' => $row->updated_at ?: $row->created_at
            );
        }

        return $pending_requests;
    }

    /**
     * Lấy tên blog
     *
     * @param int $blog_id Blog ID
     * @return string Tên blog
     */
    public static function get_blog_name($blog_id)
    {
        if (!is_multisite()) {
            return get_bloginfo('name');
        }

        switch_to_blog($blog_id);
        $name = get_bloginfo('name');
        restore_current_blog();

        return $name;
    }

    /**
     * Create database wrapper for backward compatibility
     */
    private function createDatabaseWrapper()
    {
        $configRepo = $this->configRepo;

        return new class($configRepo) {
            private $configRepo;

            public function __construct($configRepo) {
                $this->configRepo = $configRepo;
            }

            public function ensure_tables_exist() {
                $database = new TGS_Sync_Roll_Up_Database();
                $database->ensure_tables_exist();
            }

            public function get_config($blogId) {
                return $this->configRepo->getConfig($blogId);
            }

            public function get_sync_status($blogId) {
                return $this->configRepo->getSyncStatus($blogId);
            }

            public function save_config($data, $blogId) {
                return $this->configRepo->saveConfig($blogId, $data);
            }

            public function get_all_blogs() {
                $blogContext = ServiceContainer::make('BlogContext');
                return $blogContext->getAllBlogs();
            }
        };
    }

    /**
     * Create sync manager wrapper
     */
    private function createSyncManagerWrapper()
    {
        return new class() {
            public function get_recent_sync_logs($blogId, $limit) {
                $logOption = 'tgs_sync_log_' . $blogId;
                $logs = get_option($logOption, []);
                return array_slice(array_reverse($logs), 0, $limit);
            }
        };
    }

    /**
     * Create cron handler wrapper
     */
    private function createCronHandlerWrapper()
    {
        $cronService = $this->cronService;

        return new class($cronService) {
            private $cronService;

            public function __construct($cronService) {
                $this->cronService = $cronService;
            }

            public function get_next_scheduled() {
                return $this->cronService->getNextScheduled();
            }

            public function update_cron_frequency($frequency) {
                return $this->cronService->updateCronFrequency($frequency);
            }

            public function sync_specific_date($blogId, $date, $syncType) {
                return $this->cronService->syncSpecificDate($blogId, $date, $syncType);
            }

            public function rebuild_date_range($blogId, $startDate, $endDate, $syncToParents) {
                return $this->cronService->rebuildDateRange($blogId, $startDate, $endDate, $syncToParents);
            }

            public function get_recent_cron_logs($limit) {
                return $this->cronService->getRecentCronLogs($limit);
            }
        };
    }

    /**
     * Create calculator wrapper
     */
    private function createCalculatorWrapper()
    {
        $rollUpRepo = $this->rollUpRepo;

        return new class($rollUpRepo) {
            private $rollUpRepo;

            public function __construct($rollUpRepo) {
                $this->rollUpRepo = $rollUpRepo;
            }

            public function get_total_revenue_sum($blogId, $fromDate, $toDate) {
                $result = $this->rollUpRepo->sumByDateRange($blogId, $fromDate, $toDate);
                return $result['revenue'] ?? 0;
            }

            public function get_roll_up($blogId, $date) {
                return $this->rollUpRepo->findByBlogAndDate($blogId, $date);
            }

            public function get_stats_by_date_range($blogId, $fromDate, $toDate) {
                return $this->rollUpRepo->sumByDateRange($blogId, $fromDate, $toDate);
            }
        };
    }
}
