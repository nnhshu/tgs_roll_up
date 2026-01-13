<?php
/**
 * Calculate Daily Inventory Use Case
 * Business logic cho việc tính toán inventory roll-up hàng ngày
 *
 * @package TGS_Sync_Roll_Up
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class CalculateDailyInventory
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
     * Constructor
     */
    public function __construct(BlogContext $blogContext)
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->blogContext = $blogContext;
    }

    /**
     * Execute inventory calculation
     *
     * @param int $blogId Blog ID
     * @param string $date Ngày (Y-m-d)
     * @return int Number of records created
     */
    public function execute(int $blogId, string $date): int
    {
        error_log("Calculating daily inventory for blog ID {$blogId} on date {$date}");
        return $this->blogContext->executeInBlog($blogId, function() use ($blogId, $date) {
            // New Logic:
            // 1. Daily inventory (roll_up_day = specific day): Only contains transactions of that day
            // 2. Monthly total (roll_up_day = 0): Previous month total + All transactions in current month

            // Parse date
            $dateParts = explode('-', $date);
            $year = intval($dateParts[0]);
            $month = intval($dateParts[1]);
            $day = intval($dateParts[2]);

            $inventoryTable = $this->wpdb->prefix . 'inventory_roll_up';
            $productRollUpTable = $this->wpdb->prefix . 'product_roll_up';

            // Get today's transactions (types: 1=Import +, 2=Export -, 6=Damage -)
            $todayTransactions = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT local_product_name_id, global_product_name_id,
                        type, quantity, amount_after_tax
                 FROM {$productRollUpTable}
                 WHERE blog_id = %d
                 AND roll_up_year = %d
                 AND roll_up_month = %d
                 AND roll_up_day = %d
                 AND type IN (1, 2, 6)",
                $blogId,
                $year,
                $month,
                $day
            ), ARRAY_A);

            // Calculate daily inventory (only today's transactions)
            $dailyInventory = [];
            foreach ($todayTransactions as $transaction) {
                $productId = $transaction['local_product_name_id'];
                $type = intval($transaction['type']);
                $qty = floatval($transaction['quantity']);
                $value = floatval($transaction['amount_after_tax']);

                if (!isset($dailyInventory[$productId])) {
                    $dailyInventory[$productId] = [
                        'global_product_name_id' => $transaction['global_product_name_id'],
                        'qty' => 0,
                        'value' => 0,
                    ];
                }

                // Type 1 = Import (+)
                if ($type === TGS_LEDGER_TYPE_IMPORT) {
                    $dailyInventory[$productId]['qty'] += $qty;
                    $dailyInventory[$productId]['value'] += $value;
                }
                // Type 2 = Export (-)
                elseif ($type === TGS_LEDGER_TYPE_EXPORT) {
                    $dailyInventory[$productId]['qty'] -= $qty;
                    $dailyInventory[$productId]['value'] -= $value;
                }
                // Type 6 = Damage (-)
                elseif ($type === TGS_LEDGER_TYPE_DAMAGE) {
                    $dailyInventory[$productId]['qty'] -= $qty;
                    $dailyInventory[$productId]['value'] -= $value;
                }
            }

            // Save daily inventory
            $savedCount = 0;
            foreach ($dailyInventory as $productId => $data) {
                $insertData = [
                    'blog_id' => $blogId,
                    'local_product_name_id' => $productId,
                    'global_product_name_id' => $data['global_product_name_id'],
                    'roll_up_date' => $date,
                    'roll_up_day' => $day,
                    'roll_up_month' => $month,
                    'roll_up_year' => $year,
                    'inventory_qty' => $data['qty'],
                    'inventory_value' => $data['value'],
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                ];

                $result = $this->wpdb->replace($inventoryTable, $insertData);

                if ($result !== false) {
                    $savedCount++;
                }
            }

            // Update monthly total (roll_up_day = 0)
            $this->updateMonthlyTotal($blogId, $year, $month);

            return $savedCount;
        });
    }

    /**
     * Update monthly total inventory
     * Monthly total = Previous month total + All transactions in current month
     *
     * @param int $blogId Blog ID
     * @param int $year Year
     * @param int $month Month
     */
    private function updateMonthlyTotal(int $blogId, int $year, int $month): void
    {
        $inventoryTable = $this->wpdb->prefix . 'inventory_roll_up';

        // Get previous month's total (roll_up_day = 0)
        $prevMonth = $month - 1;
        $prevYear = $year;
        if ($prevMonth < 1) {
            $prevMonth = 12;
            $prevYear--;
        }

        $previousMonthTotal = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT local_product_name_id, global_product_name_id,
                    inventory_qty, inventory_value
             FROM {$inventoryTable}
             WHERE blog_id = %d
             AND roll_up_year = %d
             AND roll_up_month = %d
             AND roll_up_day = 0",
            $blogId,
            $prevYear,
            $prevMonth
        ), ARRAY_A);

        // Convert to associative array
        $monthlyInventory = [];
        foreach ($previousMonthTotal as $item) {
            $productId = $item['local_product_name_id'];
            $monthlyInventory[$productId] = [
                'global_product_name_id' => $item['global_product_name_id'],
                'qty' => floatval($item['inventory_qty']),
                'value' => floatval($item['inventory_value']),
            ];
        }

        // Get all transactions in current month
        $currentMonthTransactions = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT local_product_name_id, global_product_name_id,
                    SUM(CASE WHEN roll_up_day > 0 THEN inventory_qty ELSE 0 END) as total_qty,
                    SUM(CASE WHEN roll_up_day > 0 THEN inventory_value ELSE 0 END) as total_value
             FROM {$inventoryTable}
             WHERE blog_id = %d
             AND roll_up_year = %d
             AND roll_up_month = %d
             AND roll_up_day > 0
             GROUP BY local_product_name_id, global_product_name_id",
            $blogId,
            $year,
            $month
        ), ARRAY_A);

        // Add current month transactions to previous month total
        foreach ($currentMonthTransactions as $transaction) {
            $productId = $transaction['local_product_name_id'];

            if (!isset($monthlyInventory[$productId])) {
                $monthlyInventory[$productId] = [
                    'global_product_name_id' => $transaction['global_product_name_id'],
                    'qty' => 0,
                    'value' => 0,
                ];
            }

            $monthlyInventory[$productId]['qty'] += floatval($transaction['total_qty']);
            $monthlyInventory[$productId]['value'] += floatval($transaction['total_value']);
        }

        // Save monthly total (roll_up_day = 0)
        $firstDayOfMonth = sprintf('%04d-%02d-01', $year, $month);
        foreach ($monthlyInventory as $productId => $data) {
            $insertData = [
                'blog_id' => $blogId,
                'local_product_name_id' => $productId,
                'global_product_name_id' => $data['global_product_name_id'],
                'roll_up_date' => $firstDayOfMonth,
                'roll_up_day' => 0,
                'roll_up_month' => $month,
                'roll_up_year' => $year,
                'inventory_qty' => $data['qty'],
                'inventory_value' => $data['value'],
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ];

            $this->wpdb->replace($inventoryTable, $insertData);
        }
    }
}
