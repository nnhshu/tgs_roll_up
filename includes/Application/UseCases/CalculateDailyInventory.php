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
        return $this->blogContext->executeInBlog($blogId, function() use ($blogId, $date) {
            // Logic: Inventory(today) = Inventory(yesterday) + Transactions(today)

            // Parse date
            $dateParts = explode('-', $date);
            $year = intval($dateParts[0]);
            $month = intval($dateParts[1]);
            $day = intval($dateParts[2]);

            // Get yesterday's inventory
            $yesterday = date('Y-m-d', strtotime($date . ' -1 day'));
            $yesterdayParts = explode('-', $yesterday);

            $inventoryTable = $this->wpdb->prefix . 'inventory_roll_up';
            $productRollUpTable = $this->wpdb->prefix . 'product_roll_up';

            // Get yesterday's inventory as base
            $yesterdayInventory = $this->wpdb->get_results($this->wpdb->prepare(
                "SELECT local_product_name_id, global_product_name_id,
                        inventory_qty, inventory_value
                 FROM {$inventoryTable}
                 WHERE blog_id = %d
                 AND roll_up_year = %d
                 AND roll_up_month = %d
                 AND roll_up_day = %d",
                $blogId,
                intval($yesterdayParts[0]),
                intval($yesterdayParts[1]),
                intval($yesterdayParts[2])
            ), ARRAY_A);

            // Convert to associative array
            $inventory = [];
            foreach ($yesterdayInventory as $item) {
                $productId = $item['local_product_name_id'];
                $inventory[$productId] = [
                    'global_product_name_id' => $item['global_product_name_id'],
                    'qty' => floatval($item['inventory_qty']),
                    'value' => floatval($item['inventory_value']),
                ];
            }

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

            // Apply transactions
            foreach ($todayTransactions as $transaction) {
                $productId = $transaction['local_product_name_id'];
                $type = intval($transaction['type']);
                $qty = floatval($transaction['quantity']);
                $value = floatval($transaction['amount_after_tax']);

                if (!isset($inventory[$productId])) {
                    $inventory[$productId] = [
                        'global_product_name_id' => $transaction['global_product_name_id'],
                        'qty' => 0,
                        'value' => 0,
                    ];
                }

                // Type 1 = Import (+)
                if ($type === TGS_LEDGER_TYPE_IMPORT) {
                    $inventory[$productId]['qty'] += $qty;
                    $inventory[$productId]['value'] += $value;
                }
                // Type 2 = Export (-)
                elseif ($type === TGS_LEDGER_TYPE_EXPORT) {
                    $inventory[$productId]['qty'] -= $qty;
                    $inventory[$productId]['value'] -= $value;
                }
                // Type 6 = Damage (-)
                elseif ($type === TGS_LEDGER_TYPE_DAMAGE) {
                    $inventory[$productId]['qty'] -= $qty;
                    $inventory[$productId]['value'] -= $value;
                }
            }

            // Save to database
            $savedCount = 0;
            foreach ($inventory as $productId => $data) {
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

            return $savedCount;
        });
    }
}
