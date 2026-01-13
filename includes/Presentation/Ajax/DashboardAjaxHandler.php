<?php
/**
 * Dashboard AJAX Handler
 * Xử lý các AJAX requests liên quan đến dashboard data
 *
 * @package TGS_Sync_Roll_Up
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class DashboardAjaxHandler
{
    /**
     * @var RollUpRepositoryInterface
     */
    private $rollUpRepo;

    /**
     * @var ConfigRepositoryInterface
     */
    private $configRepo;

    /**
     * Constructor - sử dụng dependency injection
     */
    public function __construct(
        RollUpRepositoryInterface $rollUpRepo,
        ConfigRepositoryInterface $configRepo
    ) {
        $this->rollUpRepo = $rollUpRepo;
        $this->configRepo = $configRepo;
    }

    /**
     * Register AJAX hooks
     */
    public function registerHooks(): void
    {
        add_action('wp_ajax_tgs_get_dashboard_data', [$this, 'handleGetDashboardData']);
        add_action('wp_ajax_tgs_get_stats_by_date', [$this, 'handleGetStatsByDate']);
        add_action('wp_ajax_tgs_get_child_shop_detail', [$this, 'handleGetChildShopDetail']);
        add_action('wp_ajax_tgs_get_inventory_data', [$this, 'handleGetInventoryData']);
    }

    /**
     * Ensure all roll-up tables exist for current blog
     */
    private function ensureTablesExist(): void
    {
        global $wpdb;

        $tables = [
            $wpdb->prefix . 'product_roll_up',
            $wpdb->prefix . 'inventory_roll_up',
            $wpdb->prefix . 'order_roll_up'
        ];

        $needsCreation = false;
        foreach ($tables as $table) {
            $exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
            if (!$exists) {
                $needsCreation = true;
                break;
            }
        }

        if ($needsCreation) {
            require_once TGS_SYNC_ROLL_UP_PATH . 'includes/class-database.php';
            TGS_Sync_Roll_Up_Database::create_tables();
        }
    }

    /**
     * Handle get dashboard data
     */
    public function handleGetDashboardData(): void
    {
        check_ajax_referer('tgs_sync_roll_up_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'tgs-sync-roll-up')]);
        }

        // Ensure tables exist
        $this->ensureTablesExist();

        $blogId = get_current_blog_id();
        $year = isset($_POST['year']) ? intval($_POST['year']) : intval(date('Y'));
        $month = isset($_POST['month']) ? intval($_POST['month']) : intval(date('m'));

        try {
            global $wpdb;
            $table = $wpdb->prefix . 'product_roll_up';

            // Get summary stats for the month
            $stats = $wpdb->get_row($wpdb->prepare(
                "SELECT
                    COALESCE(SUM(CASE WHEN type = 10 THEN amount_after_tax ELSE 0 END), 0) as revenue_sales,
                    COALESCE(SUM(CASE WHEN type = 11 THEN amount_after_tax ELSE 0 END), 0) as revenue_returns,
                    COALESCE(SUM(CASE WHEN type = 10 THEN quantity ELSE 0 END), 0) as quantity_sales,
                    COALESCE(SUM(CASE WHEN type = 11 THEN quantity ELSE 0 END), 0) as quantity_returns
                 FROM {$table}
                 WHERE roll_up_year = %d
                 AND roll_up_month = %d",
                $year,
                $month
            ), ARRAY_A);

            // Get chart data for the month
            $chartData = $wpdb->get_results($wpdb->prepare(
                "SELECT
                    roll_up_date as date,
                    COALESCE(SUM(CASE WHEN type = 10 THEN amount_after_tax ELSE 0 END), 0) as sales,
                    COALESCE(SUM(CASE WHEN type = 11 THEN amount_after_tax ELSE 0 END), 0) as returns
                 FROM {$table}
                 WHERE roll_up_year = %d
                 AND roll_up_month = %d
                 GROUP BY roll_up_date
                 ORDER BY roll_up_date ASC",
                $year,
                $month
            ), ARRAY_A);

            // Get child shops
            $childShops = $this->configRepo->getChildBlogs($blogId, 'approved');
            $childShopData = [];

            foreach ($childShops as $childBlogId) {
                $childStats = $wpdb->get_row($wpdb->prepare(
                    "SELECT
                        COALESCE(SUM(CASE WHEN type = 10 THEN amount_after_tax ELSE 0 END), 0) as revenue
                     FROM {$table}
                     WHERE blog_id = %d
                     AND roll_up_year = %d
                     AND roll_up_month = %d",
                    $childBlogId,
                    $year,
                    $month
                ), ARRAY_A);

                $childShopData[] = [
                    'blog_id' => $childBlogId,
                    'blog_name' => $this->getBlogName($childBlogId),
                    'revenue' => $childStats['revenue'] ?? 0,
                ];
            }

            $result = [
                'year' => $year,
                'month' => $month,
                'stats' => [
                    'revenue_total' => ($stats['revenue_sales'] ?? 0) - ($stats['revenue_returns'] ?? 0),
                    'revenue_sales' => $stats['revenue_sales'] ?? 0,
                    'revenue_returns' => $stats['revenue_returns'] ?? 0,
                    'quantity_sales' => $stats['quantity_sales'] ?? 0,
                    'quantity_returns' => $stats['quantity_returns'] ?? 0,
                ],
                'chart_data' => $chartData,
                'child_shops' => $childShopData,
            ];

            wp_send_json_success($result);

        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Handle get stats by date range
     */
    public function handleGetStatsByDate(): void
    {
        check_ajax_referer('tgs_sync_roll_up_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'tgs-sync-roll-up')]);
        }

        // Ensure tables exist
        $this->ensureTablesExist();

        $blogId = get_current_blog_id();
        $fromDate = isset($_POST['from_date']) ? sanitize_text_field($_POST['from_date']) : current_time('Y-m-d');
        $toDate = isset($_POST['to_date']) ? sanitize_text_field($_POST['to_date']) : current_time('Y-m-d');

        // Validate dates
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
            wp_send_json_error(['message' => __('Invalid date format', 'tgs-sync-roll-up')]);
        }

        try {
            global $wpdb;
            $table = $wpdb->prefix . 'product_roll_up';

            $stats = $wpdb->get_row($wpdb->prepare(
                "SELECT
                    COALESCE(SUM(CASE WHEN type = 10 THEN amount_after_tax ELSE 0 END), 0) as revenue_sales,
                    COALESCE(SUM(CASE WHEN type = 11 THEN amount_after_tax ELSE 0 END), 0) as revenue_returns,
                    COALESCE(SUM(CASE WHEN type = 10 THEN quantity ELSE 0 END), 0) as quantity_sales,
                    COALESCE(SUM(CASE WHEN type = 11 THEN quantity ELSE 0 END), 0) as quantity_returns
                 FROM {$table}
                 WHERE blog_id = %d
                 AND roll_up_date BETWEEN %s AND %s",
                $blogId,
                $fromDate,
                $toDate
            ), ARRAY_A);

            if ($stats) {
                $revenueTotal = $stats['revenue_sales'] - $stats['revenue_returns'];
                $result = [
                    'revenue_total' => $revenueTotal,
                    'revenue_sales' => $stats['revenue_sales'],
                    'revenue_returns' => $stats['revenue_returns'],
                    'quantity_sales' => $stats['quantity_sales'],
                    'quantity_returns' => $stats['quantity_returns'],
                    'avg_order_value' => $stats['quantity_sales'] > 0 ? $revenueTotal / $stats['quantity_sales'] : 0,
                ];

                wp_send_json_success($result);
            } else {
                wp_send_json_success([
                    'revenue_total' => 0,
                    'revenue_sales' => 0,
                    'revenue_returns' => 0,
                    'quantity_sales' => 0,
                    'quantity_returns' => 0,
                    'avg_order_value' => 0,
                ]);
            }
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Handle get child shop detail
     */
    public function handleGetChildShopDetail(): void
    {
        check_ajax_referer('tgs_sync_roll_up_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'tgs-sync-roll-up')]);
        }

        // Ensure tables exist
        $this->ensureTablesExist();

        $childBlogId = isset($_POST['child_blog_id']) ? intval($_POST['child_blog_id']) : 0;
        $year = isset($_POST['year']) ? intval($_POST['year']) : intval(date('Y'));
        $month = isset($_POST['month']) ? intval($_POST['month']) : intval(date('m'));

        if (!$childBlogId) {
            wp_send_json_error(['message' => __('Invalid child shop ID!', 'tgs-sync-roll-up')]);
        }

        try {
            global $wpdb;
            $table = $wpdb->prefix . 'product_roll_up';

            // Get stats for child shop
            $stats = $wpdb->get_row($wpdb->prepare(
                "SELECT
                    COALESCE(SUM(CASE WHEN type = 10 THEN amount_after_tax ELSE 0 END), 0) as revenue_sales,
                    COALESCE(SUM(CASE WHEN type = 11 THEN amount_after_tax ELSE 0 END), 0) as revenue_returns,
                    COALESCE(SUM(CASE WHEN type = 10 THEN quantity ELSE 0 END), 0) as quantity_sales,
                    COALESCE(SUM(CASE WHEN type = 11 THEN quantity ELSE 0 END), 0) as quantity_returns
                 FROM {$table}
                 WHERE blog_id = %d
                 AND roll_up_year = %d
                 AND roll_up_month = %d",
                $childBlogId,
                $year,
                $month
            ), ARRAY_A);

            // Get daily breakdown
            $dailyData = $wpdb->get_results($wpdb->prepare(
                "SELECT
                    roll_up_date as date,
                    COALESCE(SUM(CASE WHEN type = 10 THEN amount_after_tax ELSE 0 END), 0) as sales,
                    COALESCE(SUM(CASE WHEN type = 11 THEN amount_after_tax ELSE 0 END), 0) as returns,
                    COALESCE(SUM(CASE WHEN type = 10 THEN quantity ELSE 0 END), 0) as quantity
                 FROM {$table}
                 WHERE blog_id = %d
                 AND roll_up_year = %d
                 AND roll_up_month = %d
                 GROUP BY roll_up_date
                 ORDER BY roll_up_date ASC",
                $childBlogId,
                $year,
                $month
            ), ARRAY_A);

            $result = [
                'blog_id' => $childBlogId,
                'blog_name' => $this->getBlogName($childBlogId),
                'year' => $year,
                'month' => $month,
                'stats' => [
                    'revenue_total' => ($stats['revenue_sales'] ?? 0) - ($stats['revenue_returns'] ?? 0),
                    'revenue_sales' => $stats['revenue_sales'] ?? 0,
                    'revenue_returns' => $stats['revenue_returns'] ?? 0,
                    'quantity_sales' => $stats['quantity_sales'] ?? 0,
                    'quantity_returns' => $stats['quantity_returns'] ?? 0,
                ],
                'daily_data' => $dailyData,
            ];

            wp_send_json_success($result);

        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Handle get inventory data
     */
    public function handleGetInventoryData(): void
    {
        check_ajax_referer('tgs_sync_roll_up_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'tgs-sync-roll-up')]);
        }

        // Ensure tables exist
        $this->ensureTablesExist();

        $blogId = get_current_blog_id();
        $date = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : current_time('Y-m-d');

        // Validate date
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            wp_send_json_error(['message' => __('Invalid date format', 'tgs-sync-roll-up')]);
        }

        try {
            global $wpdb;
            $table = $wpdb->prefix . 'inventory_roll_up';
            $dateParts = explode('-', $date);
            $day = intval($dateParts[2]);
            $month = intval($dateParts[1]);
            $year = intval($dateParts[0]);

            $inventory = $wpdb->get_results($wpdb->prepare(
                "SELECT
                    local_product_name_id,
                    global_product_name_id,
                    inventory_qty,
                    inventory_value
                 FROM {$table}
                 WHERE blog_id = %d
                   AND roll_up_day = %d
                   AND roll_up_month = %d
                   AND roll_up_year = %d
                 ORDER BY local_product_name_id ASC",
                $blogId,
                $day,
                $month,
                $year
            ), ARRAY_A);

            wp_send_json_success([
                'inventory' => $inventory,
                'date' => $date,
            ]);

        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Get blog name
     *
     * @param int $blogId Blog ID
     * @return string Blog name
     */
    private function getBlogName(int $blogId): string
    {
        if (!is_multisite()) {
            return get_bloginfo('name');
        }

        $originalBlog = get_current_blog_id();

        if ($blogId != $originalBlog) {
            switch_to_blog($blogId);
        }

        $name = get_bloginfo('name');

        if ($blogId != $originalBlog) {
            restore_current_blog();
        }

        return $name ?: "Blog #{$blogId}";
    }
}
