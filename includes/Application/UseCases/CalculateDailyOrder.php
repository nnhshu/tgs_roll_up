<?php
/**
 * Calculate Daily Order Use Case
 * Business logic cho việc tính toán order roll-up hàng ngày
 *
 * @package TGS_Sync_Roll_Up
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class CalculateDailyOrder
{
    /**
     * @var wpdb
     */
    private $wpdb;

    /**
     * @var BlogContext
     */
    private $blogContext;

    /**
     * @var DataSourceInterface
     */
    private $dataSource;

    /**
     * Constructor
     */
    public function __construct(BlogContext $blogContext, DataSourceInterface $dataSource)
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->blogContext = $blogContext;
        $this->dataSource = $dataSource;
    }

    /**
     * Execute order calculation
     *
     * @param int $blogId Blog ID
     * @param string $date Ngày (Y-m-d)
     * @return array Result with daily and monthly counts
     */
    public function execute(int $blogId, string $date): array
    {
        error_log("Calculating daily orders for blog ID {$blogId} on date {$date}");
        return $this->blogContext->executeInBlog($blogId, function() use ($blogId, $date) {
            // Logic:
            // 1. Daily order (roll_up_day = specific day): Count and value of orders on that day
            // 2. Monthly total (roll_up_day = 0): Previous month total + All orders in current month

            // Parse date
            $dateParts = explode('-', $date);
            $year = intval($dateParts[0]);
            $month = intval($dateParts[1]);
            $day = intval($dateParts[2]);

            $orderRollUpTable = $this->wpdb->prefix . 'order_roll_up';

            // Get today's orders (type = 10 = SALES, not deleted, not croned)
            $todayOrders = $this->dataSource->getOrders($date);

            if (empty($todayOrders)) {
                error_log("No orders found for date {$date}");
                return ['daily' => 0, 'monthly' => 0];
            }

            // Calculate daily order statistics
            $orderCount = count($todayOrders);
            $orderValue = 0;
            $ledgerIds = [];

            foreach ($todayOrders as $order) {
                $orderValue += floatval($order['local_ledger_total_amount']);
                $ledgerIds[] = $order['local_ledger_id'];
            }

            // Save daily order roll-up
            $insertData = [
                'blog_id' => $blogId,
                'roll_up_date' => $date,
                'roll_up_day' => $day,
                'roll_up_month' => $month,
                'roll_up_year' => $year,
                'count' => $orderCount,
                'value' => $orderValue,
                'meta' => json_encode(['ledger_ids' => $ledgerIds]),
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ];

            $this->wpdb->replace($orderRollUpTable, $insertData);

            // Mark orders as processed (is_croned = 1)
            if (!empty($ledgerIds)) {
                $this->dataSource->markOrdersAsProcessed($ledgerIds);
            }

            // Update monthly total (roll_up_day = 0)
            $this->updateMonthlyTotal($blogId, $year, $month);

            return [
                'daily' => $orderCount,
                'monthly' => $this->getMonthlyCount($blogId, $year, $month)
            ];
        });
    }

    /**
     * Update monthly total orders
     * Monthly total = Previous month total + All orders in current month
     *
     * @param int $blogId Blog ID
     * @param int $year Year
     * @param int $month Month
     */
    private function updateMonthlyTotal(int $blogId, int $year, int $month): void
    {
        $orderRollUpTable = $this->wpdb->prefix . 'order_roll_up';

        // Get previous month's total (roll_up_day = 0)
        $prevMonth = $month - 1;
        $prevYear = $year;
        if ($prevMonth < 1) {
            $prevMonth = 12;
            $prevYear--;
        }

        $previousMonthTotal = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT count, value
             FROM {$orderRollUpTable}
             WHERE blog_id = %d
             AND roll_up_year = %d
             AND roll_up_month = %d
             AND roll_up_day = 0",
            $blogId,
            $prevYear,
            $prevMonth
        ), ARRAY_A);

        $monthlyCount = $previousMonthTotal ? intval($previousMonthTotal['count']) : 0;
        $monthlyValue = $previousMonthTotal ? floatval($previousMonthTotal['value']) : 0;

        // Get all orders in current month (sum of daily records)
        $currentMonthTotal = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT
                SUM(CASE WHEN roll_up_day > 0 THEN count ELSE 0 END) as total_count,
                SUM(CASE WHEN roll_up_day > 0 THEN value ELSE 0 END) as total_value
             FROM {$orderRollUpTable}
             WHERE blog_id = %d
             AND roll_up_year = %d
             AND roll_up_month = %d
             AND roll_up_day > 0",
            $blogId,
            $year,
            $month
        ), ARRAY_A);

        if ($currentMonthTotal) {
            $monthlyCount += intval($currentMonthTotal['total_count']);
            $monthlyValue += floatval($currentMonthTotal['total_value']);
        }

        // Save monthly total (roll_up_day = 0)
        $firstDayOfMonth = sprintf('%04d-%02d-01', $year, $month);
        $insertData = [
            'blog_id' => $blogId,
            'roll_up_date' => $firstDayOfMonth,
            'roll_up_day' => 0,
            'roll_up_month' => $month,
            'roll_up_year' => $year,
            'count' => $monthlyCount,
            'value' => $monthlyValue,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ];

        $this->wpdb->replace($orderRollUpTable, $insertData);
    }

    /**
     * Get monthly order count
     *
     * @param int $blogId Blog ID
     * @param int $year Year
     * @param int $month Month
     * @return int Monthly count
     */
    private function getMonthlyCount(int $blogId, int $year, int $month): int
    {
        $orderRollUpTable = $this->wpdb->prefix . 'order_roll_up';

        $result = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT count
             FROM {$orderRollUpTable}
             WHERE blog_id = %d
             AND roll_up_year = %d
             AND roll_up_month = %d
             AND roll_up_day = 0",
            $blogId,
            $year,
            $month
        ));

        return $result ? intval($result) : 0;
    }
}
