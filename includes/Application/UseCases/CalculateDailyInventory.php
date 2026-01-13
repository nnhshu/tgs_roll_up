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
            // Logic:
            // 1. Lấy ledgers từ local_ledger với type 1 (nhập), 2 (xuất), 6 (hủy)
            // 2. Lấy items từ local_ledger_item
            // 3. Tính inventory theo local_product_name_id:
            //    - Type 1: inventory_qty += quantity, inventory_value += (quantity * price + tax_amount)
            //    - Type 2,6: inventory_qty -= quantity, inventory_value -= (quantity * price + tax_amount)
            // 4. Lưu daily record (roll_up_day = ngày trong tháng)
            // 5. Cập nhật monthly total (roll_up_day = 0) = Previous month total + Current month transactions

            // Parse date
            $dateParts = explode('-', $date);
            $year = intval($dateParts[0]);
            $month = intval($dateParts[1]);
            $day = intval($dateParts[2]);

            $inventoryTable = $this->wpdb->prefix . 'inventory_roll_up';

            // Lấy ledgers với type 1, 2, 6
            $types = [
                TGS_LEDGER_TYPE_IMPORT,  // 1 - Nhập hàng
                TGS_LEDGER_TYPE_EXPORT,  // 2 - Xuất hàng
                TGS_LEDGER_TYPE_DAMAGE,  // 6 - Hàng hỏng
            ];

            $ledgers = $this->dataSource->getLedgers($date, $types, false);

            if (empty($ledgers)) {
                error_log("No ledgers found for inventory on date {$date}");
                return 0;
            }

            // Lấy ledger IDs
            $ledgerIds = array_column($ledgers, 'local_ledger_id');

            // Lấy items từ local_ledger_item
            $items = $this->dataSource->getLedgerItems($ledgerIds);

            if (empty($items)) {
                error_log("No items found for ledgers");
                return 0;
            }

            // Group items by ledger_id để biết type
            $itemsByLedger = [];
            foreach ($items as $item) {
                $ledgerId = $item['local_ledger_id'];
                if (!isset($itemsByLedger[$ledgerId])) {
                    $itemsByLedger[$ledgerId] = [];
                }
                $itemsByLedger[$ledgerId][] = $item;
            }

            // Tính inventory theo product
            $dailyInventory = [];

            foreach ($ledgers as $ledger) {
                $ledgerId = $ledger['local_ledger_id'];
                $ledgerType = intval($ledger['local_ledger_type']);

                if (!isset($itemsByLedger[$ledgerId])) {
                    continue;
                }

                foreach ($itemsByLedger[$ledgerId] as $item) {
                    $productId = $item['local_product_name_id'];
                    $quantity = floatval($item['quantity'] ?? 0);
                    $price = floatval($item['price'] ?? 0);
                    $taxAmount = floatval($item['local_ledger_item_tax_amount'] ?? 0);

                    // Tính value = quantity * price + tax
                    $value = ($quantity * $price) + $taxAmount;

                    if (!isset($dailyInventory[$productId])) {
                        $dailyInventory[$productId] = [
                            'global_product_name_id' => $item['global_product_name_id'] ?? null,
                            'qty' => 0,
                            'value' => 0,
                        ];
                    }

                    // Type 1 = Import (+)
                    if ($ledgerType === TGS_LEDGER_TYPE_IMPORT) {
                        $dailyInventory[$productId]['qty'] += $quantity;
                        $dailyInventory[$productId]['value'] += $value;
                    }
                    // Type 2 = Export (-) hoặc Type 6 = Damage (-)
                    elseif ($ledgerType === TGS_LEDGER_TYPE_EXPORT || $ledgerType === TGS_LEDGER_TYPE_DAMAGE) {
                        $dailyInventory[$productId]['qty'] -= $quantity;
                        $dailyInventory[$productId]['value'] -= $value;
                    }
                }
            }

            // Save daily inventory (sử dụng INSERT ... ON DUPLICATE KEY UPDATE)
            $savedCount = 0;
            foreach ($dailyInventory as $productId => $data) {
                $this->wpdb->query($this->wpdb->prepare(
                    "INSERT INTO {$inventoryTable}
                    (blog_id, local_product_name_id, global_product_name_id,
                     roll_up_date, roll_up_day, roll_up_month, roll_up_year,
                     inventory_qty, inventory_value, created_at, updated_at)
                    VALUES (%d, %d, %d, %s, %d, %d, %d, %f, %f, %s, %s)
                    ON DUPLICATE KEY UPDATE
                        inventory_qty = inventory_qty + VALUES(inventory_qty),
                        inventory_value = inventory_value + VALUES(inventory_value),
                        updated_at = VALUES(updated_at)",
                    $blogId,
                    $productId,
                    $data['global_product_name_id'],
                    $date,
                    $day,
                    $month,
                    $year,
                    $data['qty'],
                    $data['value'],
                    current_time('mysql'),
                    current_time('mysql')
                ));

                $savedCount++;

                // Đồng thời cập nhật monthly total (day = 0)
                $this->updateMonthlyTotalForProduct($blogId, $productId, $data['global_product_name_id'], $year, $month, $data['qty'], $data['value']);
            }

            return $savedCount;
        });
    }

    /**
     * Update monthly total for a specific product
     * Monthly total (day=0) = Previous month total + Current month transactions
     *
     * @param int $blogId Blog ID
     * @param int $productId Product ID
     * @param int|null $globalProductId Global product ID
     * @param int $year Year
     * @param int $month Month
     * @param float $qtyChange Quantity change
     * @param float $valueChange Value change
     */
    private function updateMonthlyTotalForProduct(
        int $blogId,
        int $productId,
        ?int $globalProductId,
        int $year,
        int $month,
        float $qtyChange,
        float $valueChange
    ): void {
        $inventoryTable = $this->wpdb->prefix . 'inventory_roll_up';
        $firstDayOfMonth = sprintf('%04d-%02d-01', $year, $month);

        // Lấy tồn tháng trước (day = 0 của tháng trước)
        $prevMonth = $month - 1;
        $prevYear = $year;
        if ($prevMonth < 1) {
            $prevMonth = 12;
            $prevYear--;
        }

        $prevMonthTotal = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT inventory_qty, inventory_value
             FROM {$inventoryTable}
             WHERE blog_id = %d
             AND local_product_name_id = %d
             AND roll_up_year = %d
             AND roll_up_month = %d
             AND roll_up_day = 0",
            $blogId,
            $productId,
            $prevYear,
            $prevMonth
        ), ARRAY_A);

        $prevQty = $prevMonthTotal ? floatval($prevMonthTotal['inventory_qty']) : 0;
        $prevValue = $prevMonthTotal ? floatval($prevMonthTotal['inventory_value']) : 0;

        // Lấy tổng giao dịch trong tháng hiện tại (day > 0)
        $currentMonthTotal = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT
                SUM(inventory_qty) as total_qty,
                SUM(inventory_value) as total_value
             FROM {$inventoryTable}
             WHERE blog_id = %d
             AND local_product_name_id = %d
             AND roll_up_year = %d
             AND roll_up_month = %d
             AND roll_up_day > 0",
            $blogId,
            $productId,
            $year,
            $month
        ), ARRAY_A);

        $currentQty = $currentMonthTotal ? floatval($currentMonthTotal['total_qty']) : 0;
        $currentValue = $currentMonthTotal ? floatval($currentMonthTotal['total_value']) : 0;

        // Tổng tháng = Tháng trước + Giao dịch tháng này
        $monthlyQty = $prevQty + $currentQty;
        $monthlyValue = $prevValue + $currentValue;

        // Lưu hoặc update monthly total
        $this->wpdb->query($this->wpdb->prepare(
            "INSERT INTO {$inventoryTable}
            (blog_id, local_product_name_id, global_product_name_id,
             roll_up_date, roll_up_day, roll_up_month, roll_up_year,
             inventory_qty, inventory_value, created_at, updated_at)
            VALUES (%d, %d, %d, %s, 0, %d, %d, %f, %f, %s, %s)
            ON DUPLICATE KEY UPDATE
                inventory_qty = VALUES(inventory_qty),
                inventory_value = VALUES(inventory_value),
                updated_at = VALUES(updated_at)",
            $blogId,
            $productId,
            $globalProductId,
            $firstDayOfMonth,
            $month,
            $year,
            $monthlyQty,
            $monthlyValue,
            current_time('mysql'),
            current_time('mysql')
        ));
    }
}
