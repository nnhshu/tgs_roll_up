<?php
/**
 * TGS Shop Data Source Implementation
 * Adapter cho tgs_shop_management plugin tables
 *
 * @package TGS_Sync_Roll_Up
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once TGS_SYNC_ROLL_UP_PATH . 'includes/Core/Interfaces/DataSourceInterface.php';

class TgsShopDataSource implements DataSourceInterface
{
    /**
     * @var wpdb
     */
    private $wpdb;

    /**
     * Constructor
     */
    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
    }

    /**
     * {@inheritdoc}
     */
    public function isAvailable(): bool
    {
        // Kiểm tra xem các constants và tables có tồn tại không
        if (!defined('TGS_TABLE_LOCAL_LEDGER') || !defined('TGS_TABLE_LOCAL_LEDGER_ITEM')) {
            return false;
        }

        // Kiểm tra bảng có tồn tại không
        $table = TGS_TABLE_LOCAL_LEDGER;
        $exists = $this->wpdb->get_var("SHOW TABLES LIKE '{$table}'");

        return !empty($exists);
    }

    /**
     * {@inheritdoc}
     * 
     * Logic mới:
     * - Lấy ledger với updated_at = date (thời gian approve)
     * - Với type 1, 2: Cần check parent đã approve
     * - Với type 6, 7, 8: Chỉ cần status = 4, không cần check parent
     */
    public function getLedgers(string $date, array $types = [], bool $processedOnly = false): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        $table = TGS_TABLE_LOCAL_LEDGER;
        
        // Base conditions: updated_at = date (thời gian approve), status = 4
        $where = [
            "DATE(updated_at) = %s",  // Thời gian approve = ngày hôm nay
            "local_ledger_status = 4", // Đã approve
            "(is_deleted IS NULL OR is_deleted = 0)"
        ];
        $params = [$date];

        if (!empty($types)) {
            $placeholders = implode(',', array_fill(0, count($types), '%d'));
            $where[] = "local_ledger_type IN ({$placeholders})";
            $params = array_merge($params, $types);
        }

        if ($processedOnly) {
            $where[] = "(is_croned IS NULL OR is_croned = 0)";
        }
        
        $where[] = "(is_croned IS NULL OR is_croned = 0)";

        $whereClause = implode(' AND ', $where);
        
        // Lấy tất cả ledger con (type 1, 2, 6, 7, 8) đã approve
        $query = "SELECT * FROM {$table} WHERE {$whereClause} ORDER BY local_ledger_id ASC";
        
        $childLedgers = $this->wpdb->get_results(
            $this->wpdb->prepare($query, ...$params),
            ARRAY_A
        ) ?: [];
        
        // Phân loại: Type cần check parent (1, 2) và type không cần check (6, 7, 8)
        $typesNeedParentCheck = [1, 2]; // Nhập kho, Xuất kho
        $resultLedgers = [];
        
        foreach ($childLedgers as $child) {
            $childType = intval($child['local_ledger_type']);
            $parentId = isset($child['local_ledger_parent_id']) ? intval($child['local_ledger_parent_id']) : null;
            
            // Type 6, 7, 8: Không cần check parent, lấy trực tiếp
            if (!in_array($childType, $typesNeedParentCheck) || $parentId === null || $parentId === 0) {
                $resultLedgers[] = $child;
                continue;
            }
            
            // Type 1, 2: Kiểm tra parent đã approve chưa
            $parent = $this->wpdb->get_row($this->wpdb->prepare(
                "SELECT local_ledger_type, local_ledger_status 
                 FROM {$table} 
                 WHERE local_ledger_id = %d 
                 AND local_ledger_status = 4
                 AND (is_deleted IS NULL OR is_deleted = 0)",
                $parentId
            ), ARRAY_A);
            
            // Chỉ lấy nếu parent đã approve
            if ($parent && intval($parent['local_ledger_status']) === 4) {
                $resultLedgers[] = $child;
            }
        }
        
        return $resultLedgers;
    }

    /**
     * {@inheritdoc}
     *
     * @param array $ledgers Array of ledger records (containing local_ledger_item_id column)
     * @return array Array of ledger items
     */
    public function getLedgerItems(array $ledgers): array
    {
        if (!$this->isAvailable() || empty($ledgers)) {
            return [];
        }

        if (!defined('TGS_TABLE_LOCAL_LEDGER_ITEM')) {
            return [];
        }

        // Thu thập tất cả item IDs từ cột local_ledger_item_id (JSON format)
        $allItemIds = [];
        foreach ($ledgers as $ledger) {
            if (!empty($ledger['local_ledger_item_id'])) {
                $itemIds = json_decode($ledger['local_ledger_item_id'], true);
                if (is_array($itemIds)) {
                    $allItemIds = array_merge($allItemIds, $itemIds);
                }
            }
        }

        // Loại bỏ duplicate
        $allItemIds = array_unique($allItemIds);
        if (empty($allItemIds)) {
            return [];
        }

        $itemTable = TGS_TABLE_LOCAL_LEDGER_ITEM;
        $ledgerTable = TGS_TABLE_LOCAL_LEDGER;
        $placeholders = implode(',', array_fill(0, count($allItemIds), '%d'));

        // JOIN với local_ledger để lấy local_ledger_source
        $query = "SELECT i.*, l.local_ledger_source
                  FROM {$itemTable} i
                  LEFT JOIN {$ledgerTable} l ON i.local_ledger_id = l.local_ledger_id
                  WHERE i.local_ledger_item_id IN ({$placeholders})";

        return $this->wpdb->get_results(
            $this->wpdb->prepare($query, ...$allItemIds),
            ARRAY_A
        ) ?: [];
    }

    /**
     * {@inheritdoc}
     */
    public function getProducts(array $productIds = []): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        if (!defined('TGS_TABLE_LOCAL_PRODUCT_NAME')) {
            return [];
        }

        $table = TGS_TABLE_LOCAL_PRODUCT_NAME;

        if (empty($productIds)) {
            $query = "SELECT * FROM {$table}";
            return $this->wpdb->get_results($query, ARRAY_A) ?: [];
        }

        $placeholders = implode(',', array_fill(0, count($productIds), '%d'));
        $query = "SELECT * FROM {$table} WHERE id IN ({$placeholders})";

        return $this->wpdb->get_results(
            $this->wpdb->prepare($query, ...$productIds),
            ARRAY_A
        ) ?: [];
    }

    /**
     * {@inheritdoc}
     */
    public function getProductLots(array $productIds = []): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        if (!defined('TGS_TABLE_LOCAL_PRODUCT_LOT')) {
            return [];
        }

        $table = TGS_TABLE_LOCAL_PRODUCT_LOT;

        if (empty($productIds)) {
            $query = "SELECT * FROM {$table}";
            return $this->wpdb->get_results($query, ARRAY_A) ?: [];
        }

        $placeholders = implode(',', array_fill(0, count($productIds), '%d'));
        $query = "SELECT * FROM {$table} WHERE local_product_name_id IN ({$placeholders})";

        return $this->wpdb->get_results(
            $this->wpdb->prepare($query, ...$productIds),
            ARRAY_A
        ) ?: [];
    }

    /**
     * {@inheritdoc}
     */
    public function markLedgersAsProcessed(array $ledgerIds): bool
    {
        if (!$this->isAvailable() || empty($ledgerIds)) {
            return false;
        }

        $table = TGS_TABLE_LOCAL_LEDGER;
        $placeholders = implode(',', array_fill(0, count($ledgerIds), '%d'));

        $query = "UPDATE {$table} SET is_croned = 1 WHERE local_ledger_id IN ({$placeholders})";

        $result = $this->wpdb->query(
            $this->wpdb->prepare($query, ...$ledgerIds)
        );

        return $result !== false;
    }

    /**
     * Get orders (ledger type = 10 SALES)
     * 
     * Logic mới: Lấy parent (type 10) từ phiếu con (type 2 - xuất kho) đã approve hôm nay
     *
     * @param string $date Date (Y-m-d)
     * @return array Orders
     */
    public function getOrders(string $date): array
    {
        if (!$this->isAvailable()) {
            return [];
        }

        $table = TGS_TABLE_LOCAL_LEDGER;

        // Lấy phiếu bán (type 10) từ phiếu con (type 2 - xuất kho) đã approve hôm nay
        $query = "SELECT DISTINCT p.*
                  FROM {$table} c
                  INNER JOIN {$table} p ON c.local_ledger_parent_id = p.local_ledger_id
                  WHERE DATE(c.updated_at) = %s
                  AND c.local_ledger_type = 2
                  AND c.local_ledger_status = 4
                  AND p.local_ledger_type = 10
                  AND p.local_ledger_status = 4
                  AND (c.is_deleted IS NULL OR c.is_deleted = 0)
                  AND (p.is_deleted IS NULL OR p.is_deleted = 0)
                  AND (c.is_croned IS NULL OR c.is_croned = 0)
                  AND (p.is_croned IS NULL OR p.is_croned = 0)
                  ORDER BY p.local_ledger_id ASC";

        return $this->wpdb->get_results(
            $this->wpdb->prepare($query, $date),
            ARRAY_A
        ) ?: [];
    }

    /**
     * Mark orders as processed (is_croned = 1)
     *
     * @param array $ledgerIds Ledger IDs
     * @return bool Success
     */
    public function markOrdersAsProcessed(array $ledgerIds): bool
    {
        return $this->markLedgersAsProcessed($ledgerIds);
    }
}
