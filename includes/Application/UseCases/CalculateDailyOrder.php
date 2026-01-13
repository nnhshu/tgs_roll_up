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
     * Execute order calculation (query ledgers from database)
     *
     * @param int $blogId Blog ID
     * @param string $date Ngày (Y-m-d)
     * @return array Result with daily count and ledger_ids
     */
    public function execute(int $blogId, string $date): array
    {
        error_log("Calculating daily orders for blog ID {$blogId} on date {$date}");
        return $this->blogContext->executeInBlog($blogId, function() use ($blogId, $date) {
            // Get today's orders (type = 10 = SALES, not deleted, not croned)
            $todayOrders = $this->dataSource->getOrders($date);

            if (empty($todayOrders)) {
                error_log("No orders found for date {$date}");
                return ['daily' => 0, 'monthly' => 0, 'ledger_ids' => []];
            }

            return $this->processOrders($blogId, $date, $todayOrders);
        });
    }

    /**
     * Execute order calculation with pre-fetched ledgers
     *
     * @param int $blogId Blog ID
     * @param string $date Ngày (Y-m-d)
     * @param array $ledgers Pre-fetched ledgers (type 10 - SALES)
     * @return array Result
     */
    public function executeWithLedgers(int $blogId, string $date, array $ledgers): array
    {
        return $this->blogContext->executeInBlog($blogId, function() use ($blogId, $date, $ledgers) {
            if (empty($ledgers)) {
                return ['daily' => 0];
            }
            return $this->processOrders($blogId, $date, $ledgers);
        });
    }

    /**
     * Process orders and save roll-up data
     *
     * @param int $blogId Blog ID
     * @param string $date Date
     * @param array $orders Orders to process
     * @return array Result
     */
    private function processOrders(int $blogId, string $date, array $orders): array
    {
        // Parse date
        $dateParts = explode('-', $date);
        $year = intval($dateParts[0]);
        $month = intval($dateParts[1]);
        $day = intval($dateParts[2]);

        $orderRollUpTable = $this->wpdb->prefix . 'order_roll_up';

        // Calculate daily order statistics
        $orderCount = count($orders);
        $orderValue = 0;
        $ledgerIds = [];

        foreach ($orders as $order) {
            $orderValue += floatval($order['local_ledger_total_amount']);
            $ledgerIds[] = $order['local_ledger_id'];
        }

        // Save daily order roll-up using INSERT ... ON DUPLICATE KEY UPDATE để cộng dồn
        $metaJson = json_encode(['ledger_ids' => $ledgerIds]);
        $createdAt = current_time('mysql');
        $updatedAt = current_time('mysql');

        $this->wpdb->query($this->wpdb->prepare(
            "INSERT INTO {$orderRollUpTable}
            (blog_id, roll_up_date, roll_up_day, roll_up_month, roll_up_year, count, value, meta, created_at, updated_at)
            VALUES (%d, %s, %d, %d, %d, %d, %f, %s, %s, %s)
            ON DUPLICATE KEY UPDATE
                count = count + VALUES(count),
                value = value + VALUES(value),
                meta = JSON_MERGE_PRESERVE(COALESCE(meta, '{}'), VALUES(meta)),
                updated_at = VALUES(updated_at)",
            $blogId,
            $date,
            $day,
            $month,
            $year,
            $orderCount,
            $orderValue,
            $metaJson,
            $createdAt,
            $updatedAt
        ));

        return [
            'daily' => $orderCount,
            'ledger_ids' => $ledgerIds,
        ];
    }
}
