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
     * @return array ['saved_count' => int, 'ledger_ids' => array]
     */
    public function execute(int $blogId, string $date): array
    {
        error_log("Calculating daily inventory for blog ID {$blogId} on date {$date}");
        return $this->blogContext->executeInBlog($blogId, function() use ($blogId, $date) {
            // Logic:
            // 1. Duplicate tồn kho từ ngày hôm trước sang ngày hôm nay
            // 2. Lấy ledgers từ local_ledger với type 1 (nhập), 2 (xuất), 6 (hủy)
            // 3. Lấy items từ local_ledger_item
            // 4. Tính inventory theo local_product_name_id:
            //    - Type 1: inventory_qty += quantity, inventory_value += (quantity * price + tax_amount)
            //    - Type 2,6: inventory_qty -= quantity, inventory_value -= (quantity * price + tax_amount)
            // 5. Lưu daily record và cộng dồn với tồn kho của ngày hôm trước

            // Parse date
            $dateParts = explode('-', $date);
            $year = intval($dateParts[0]);
            $month = intval($dateParts[1]);
            $day = intval($dateParts[2]);

            $inventoryTable = $this->wpdb->prefix . 'inventory_roll_up';

            // BƯỚC 1: Duplicate các record của ngày hôm trước và thay bằng ngày hôm nay
            // Điều này đảm bảo hàng tồn được cộng dồn từ ngày hôm trước
            $this->duplicateYesterdayInventory($blogId, $date);

            // Lấy ledgers với type 1, 2, 6
            $types = [
                TGS_LEDGER_TYPE_IMPORT,  // 1 - Nhập hàng
                TGS_LEDGER_TYPE_EXPORT,  // 2 - Xuất hàng
                TGS_LEDGER_TYPE_DAMAGE,  // 6 - Hàng hỏng
            ];

            $ledgers = $this->dataSource->getLedgers($date, $types, false);

            if (empty($ledgers)) {
                error_log("No ledgers found for inventory on date {$date}");
                return ['saved_count' => 0, 'ledger_ids' => []];
            }

            // Lấy ledger IDs
            $ledgerIds = array_column($ledgers, 'local_ledger_id');

            // Lấy items từ local_ledger_item (truyền toàn bộ ledgers để parse JSON local_ledger_item_id)
            $items = $this->dataSource->getLedgerItems($ledgers);

            if (empty($items)) {
                error_log("No items found for ledgers");
                return ['saved_count' => 0, 'ledger_ids' => $ledgerIds];
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

            error_log("Calculating inventory from ledgers and items");
            error_log(json_encode($itemsByLedger));
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
            }

            // Không đánh cờ is_croned ở đây, trả về ledger_ids để CronService xử lý
            return [
                'saved_count' => $savedCount,
                'ledger_ids' => $ledgerIds,
            ];
        });
    }

    /**
     * Duplicate yesterday's inventory to today
     * Nếu ngày hôm trước có tồn kho, copy sang ngày hôm nay để tính tồn luỹ kế
     *
     * @param int $blogId Blog ID
     * @param string $date Date (Y-m-d)
     */
    private function duplicateYesterdayInventory(int $blogId, string $date): void
    {
        $inventoryTable = $this->wpdb->prefix . 'inventory_roll_up';

        // Tính ngày hôm trước
        $yesterday = date('Y-m-d', strtotime($date . ' -1 day'));
        $yesterdayParts = explode('-', $yesterday);
        $yesterdayYear = intval($yesterdayParts[0]);
        $yesterdayMonth = intval($yesterdayParts[1]);
        $yesterdayDay = intval($yesterdayParts[2]);

        // Tính ngày hôm nay
        $dateParts = explode('-', $date);
        $year = intval($dateParts[0]);
        $month = intval($dateParts[1]);
        $day = intval($dateParts[2]);

        // Kiểm tra xem ngày hôm nay đã có inventory chưa
        $existingToday = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT local_product_name_id FROM {$inventoryTable}
             WHERE blog_id = %d
             AND roll_up_year = %d
             AND roll_up_month = %d
             AND roll_up_day = %d",
            $blogId,
            $year,
            $month,
            $day
        ), ARRAY_A);

        // Nếu đã có inventory ngày hôm nay, không cần duplicate
        if (!empty($existingToday)) {
            return;
        }

        // Lấy tất cả inventory của ngày hôm trước
        $yesterdayInventory = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT * FROM {$inventoryTable}
             WHERE blog_id = %d
             AND roll_up_year = %d
             AND roll_up_month = %d
             AND roll_up_day = %d",
            $blogId,
            $yesterdayYear,
            $yesterdayMonth,
            $yesterdayDay
        ), ARRAY_A);

        // Nếu ngày hôm trước không có tồn kho, không làm gì
        if (empty($yesterdayInventory)) {
            return;
        }

        // Duplicate từng product từ ngày hôm trước sang ngày hôm nay
        foreach ($yesterdayInventory as $record) {
            $this->wpdb->query($this->wpdb->prepare(
                "INSERT INTO {$inventoryTable}
                (blog_id, local_product_name_id, global_product_name_id,
                 roll_up_date, roll_up_day, roll_up_month, roll_up_year,
                 inventory_qty, inventory_value, meta, created_at, updated_at)
                VALUES (%d, %d, %d, %s, %d, %d, %d, %f, %f, %s, %s, %s)
                ON DUPLICATE KEY UPDATE
                    inventory_qty = VALUES(inventory_qty),
                    inventory_value = VALUES(inventory_value),
                    updated_at = VALUES(updated_at)",
                $blogId,
                $record['local_product_name_id'],
                $record['global_product_name_id'],
                $date,
                $day,
                $month,
                $year,
                $record['inventory_qty'],
                $record['inventory_value'],
                $record['meta'],
                current_time('mysql'),
                current_time('mysql')
            ));
        }
    }
}
