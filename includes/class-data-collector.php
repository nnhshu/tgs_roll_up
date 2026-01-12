<?php
/**
 * Data Collector class for TGS Sync Roll-Up
 *
 * Thu thập dữ liệu mới từ các bảng local_ledger, local_product_lot, local_ledger_person
 *
 * @package TGS_Sync_Roll_Up
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Sync_Roll_Up_Data_Collector
{
    /**
     * Constructor
     */
    public function __construct()
    {
    }

    /**
     * Collect new data since last sync
     *
     * @param array $config Current config with tracking IDs
     * @return array New data collected
     */
    public function collect_new_data($config)
    {
        $data = array(
            'ledgers' => $this->get_new_ledgers($config['last_processed_ledger_id']),
            'lots'    => $this->get_new_lots($config['last_processed_lot_id']),
            'persons' => $this->get_new_persons($config['last_processed_person_id']),
        );

        // Get max IDs for tracking
        $data['max_ids'] = array(
            'ledger_id' => $this->get_max_id($data['ledgers'], 'local_ledger_id'),
            'lot_id'    => $this->get_max_id($data['lots'], 'local_product_lot_id'),
            'person_id' => $this->get_max_id($data['persons'], 'local_ledger_person_id'),
        );

        return $data;
    }

    /**
     * Get new ledgers since last processed ID
     */
    public function get_new_ledgers($last_id = 0)
    {
        global $wpdb;

        if (!defined('TGS_TABLE_LOCAL_LEDGER')) {
            return array();
        }

        $table = TGS_TABLE_LOCAL_LEDGER;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                WHERE local_ledger_id > %d
                  AND is_deleted = 0
                ORDER BY local_ledger_id ASC
                LIMIT 1000",
                $last_id
            ));
    }

    /**
     * Get new product lots since last processed ID
     */
    public function get_new_lots($last_id = 0)
    {
        global $wpdb;

        if (!defined('TGS_TABLE_LOCAL_PRODUCT_LOT')) {
            return array();
        }

        $table = TGS_TABLE_LOCAL_PRODUCT_LOT;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                WHERE local_product_lot_id > %d
                  AND is_deleted = 0
                ORDER BY local_product_lot_id ASC
                LIMIT 1000",
                $last_id
            ));
    }

    /**
     * Get new persons since last processed ID
     */
    public function get_new_persons($last_id = 0)
    {
        global $wpdb;

        if (!defined('TGS_TABLE_LOCAL_LEDGER_PERSON')) {
            return array();
        }

        $table = TGS_TABLE_LOCAL_LEDGER_PERSON;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                WHERE local_ledger_person_id > %d
                  AND is_deleted = 0
                ORDER BY local_ledger_person_id ASC
                LIMIT 1000",
                $last_id
            ));
    }

    /**
     * Get all ledgers for a specific date
     *
     * @param string $date Ngày (Y-m-d)
     * @param string $sync_type Loại đồng bộ: 'all', 'products', 'orders', 'inventory'
     * @return array Danh sách ledgers
     */
    public function get_ledgers_by_date($date, $sync_type = 'all')
    {
        global $wpdb;

        if (!defined('TGS_TABLE_LOCAL_LEDGER')) {
            return array();
        }

        $table = TGS_TABLE_LOCAL_LEDGER;

        // Xác định loại ledger type dựa trên sync_type
        $ledger_types = array();

        switch ($sync_type) {
            case 'orders':
                // Chỉ đồng bộ đơn hàng (bán, mua, trả)
                $ledger_types = array(
                    TGS_LEDGER_TYPE_SALES,      // 10 - Bán hàng
                    TGS_LEDGER_TYPE_RETURN,     // 11 - Trả hàng
                    TGS_LEDGER_TYPE_PURCHASE    // 9 - Mua hàng
                );
                break;

            case 'inventory':
                // Chỉ đồng bộ tồn kho (nhập/xuất nội bộ, hàng hỏng)
                $ledger_types = array(
                    TGS_LEDGER_TYPE_IMPORT,     // 1 - Nhập nội bộ
                    TGS_LEDGER_TYPE_EXPORT,     // 2 - Xuất nội bộ
                    TGS_LEDGER_TYPE_DAMAGE      // 6 - Hàng hỏng
                );
                break;

            case 'products':
                // Sản phẩm: đồng bộ tất cả loại vì tất cả đều liên quan đến sản phẩm
                $ledger_types = array(
                    TGS_LEDGER_TYPE_IMPORT,
                    TGS_LEDGER_TYPE_EXPORT,
                    TGS_LEDGER_TYPE_DAMAGE,
                    TGS_LEDGER_TYPE_PURCHASE,
                    TGS_LEDGER_TYPE_SALES,
                    TGS_LEDGER_TYPE_RETURN
                );
                break;

            case 'all':
            default:
                // Mặc định: chỉ đồng bộ sales và return (logic cũ)
                $ledger_types = array(
                    TGS_LEDGER_TYPE_SALES,
                    TGS_LEDGER_TYPE_RETURN
                );
                break;
        }

        // Tạo placeholders cho IN clause
        $placeholders = implode(',', array_fill(0, count($ledger_types), '%d'));

        $query = "SELECT * FROM {$table}
                  WHERE DATE(created_at) = %s
                    AND is_deleted = 0
                    AND is_croned = 0
                    AND local_ledger_type IN ({$placeholders})
                  ORDER BY local_ledger_id ASC";

        // Merge tham số: date + ledger_types
        $params = array_merge(array($date), $ledger_types);

        return $wpdb->get_results(
            $wpdb->prepare($query, $params)
        );
    }

    /**
     * Get all ledgers for a specific date
     */
    public function get_children_ledgers($ids)
    {
        global $wpdb;

        if (!defined('TGS_TABLE_LOCAL_LEDGER')) {
            return array();
        }

        // Kiểm tra nếu $ids rỗng
        if (empty($ids) || !is_array($ids)) {
            return array();
        }

        $table = TGS_TABLE_LOCAL_LEDGER;

        $ids_placeholder = implode(',', array_map('intval', $ids));

        // Kiểm tra lại sau khi implode để đảm bảo không rỗng
        if (empty($ids_placeholder)) {
            return array();
        }

        return $wpdb->get_results(
            "SELECT * FROM {$table}
            WHERE local_ledger_parent_id IN ({$ids_placeholder})
            ORDER BY local_ledger_id ASC",
        );
    }

    /**
     * Get ledger items by ledger IDs
     */
    public function get_ledger_items($ledger_ids)
    {
        global $wpdb;

        if (!defined('TGS_TABLE_LOCAL_LEDGER_ITEM') || empty($ledger_ids)) {
            return array();
        }

        $table = TGS_TABLE_LOCAL_LEDGER_ITEM;
        $ids_placeholder = implode(',', array_map('intval', $ledger_ids));

        // Kiểm tra lại sau khi implode
        if (empty($ids_placeholder)) {
            return array();
        }

        return $wpdb->get_results(
            "SELECT * FROM {$table}
            WHERE local_ledger_id IN ({$ids_placeholder})
              AND is_deleted = 0");
    }

    /**
     * Get ledger items by item IDs (mới - cho logic lấy từ local_ledger_item_id)
     */
    public function get_ledger_items_by_ids($item_ids)
    {
        global $wpdb;

        if (!defined('TGS_TABLE_LOCAL_LEDGER_ITEM') || empty($item_ids)) {
            return array();
        }

        $table = TGS_TABLE_LOCAL_LEDGER_ITEM;
        $ids_placeholder = implode(',', array_map('intval', $item_ids));

        // Kiểm tra lại sau khi implode
        if (empty($ids_placeholder)) {
            return array();
        }

        return $wpdb->get_results(
            "SELECT * FROM {$table}
            WHERE local_ledger_item_id IN ({$ids_placeholder})
              AND is_deleted = 0");
    }

    /**
     * Get product info by IDs
     */
    public function get_products($product_ids)
    {
        global $wpdb;

        if (!defined('TGS_TABLE_LOCAL_PRODUCT_NAME') || empty($product_ids)) {
            return array();
        }

        $table = TGS_TABLE_LOCAL_PRODUCT_NAME;
        $ids_placeholder = implode(',', array_map('intval', $product_ids));

        // Kiểm tra lại sau khi implode
        if (empty($ids_placeholder)) {
            return array();
        }

        return $wpdb->get_results(
            "SELECT * FROM {$table}
            WHERE local_product_name_id IN ({$ids_placeholder})
              AND is_deleted = 0");
    }

    /**
     * Get all active lots (in stock)
     */
    public function get_active_lots()
    {
        global $wpdb;

        if (!defined('TGS_TABLE_LOCAL_PRODUCT_LOT')) {
            return array();
        }

        $table = TGS_TABLE_LOCAL_PRODUCT_LOT;

        // local_product_lot_is_active = 1 means in stock
        return $wpdb->get_results(
            "SELECT * FROM {$table}
            WHERE local_product_lot_is_active = 1
              AND is_deleted = 0");
    }

    /**
     * Get lots expiring within N days
     */
    public function get_expiring_lots($days = 30)
    {
        global $wpdb;

        if (!defined('TGS_TABLE_LOCAL_PRODUCT_LOT')) {
            return array();
        }

        $table = TGS_TABLE_LOCAL_PRODUCT_LOT;
        $expire_date = date('Y-m-d', strtotime("+{$days} days"));

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                WHERE local_product_lot_is_active = 1
                  AND exp_date IS NOT NULL
                  AND exp_date <= %s
                  AND exp_date >= CURDATE()
                  AND is_deleted = 0",
                $expire_date
            ));
    }

    /**
     * Get expired lots
     */
    public function get_expired_lots()
    {
        global $wpdb;

        if (!defined('TGS_TABLE_LOCAL_PRODUCT_LOT')) {
            return array();
        }

        $table = TGS_TABLE_LOCAL_PRODUCT_LOT;

        return $wpdb->get_results(
            "SELECT * FROM {$table}
            WHERE local_product_lot_is_active = 1
              AND exp_date IS NOT NULL
              AND exp_date < CURDATE()
              AND is_deleted = 0");
    }

    /**
     * Get new customers created on a specific date
     */
    public function get_new_customers_by_date($date)
    {
        global $wpdb;

        if (!defined('TGS_TABLE_LOCAL_LEDGER_PERSON')) {
            return array();
        }

        $table = TGS_TABLE_LOCAL_LEDGER_PERSON;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$table}
                WHERE DATE(created_at) = %s
                  AND local_ledger_person_type = %d
                  AND is_deleted = 0",
                $date,
                TGS_PERSON_TYPE_CUSTOMER
            ));
    }

    /**
     * Get returning customers (customers who made purchases in previous months)
     */
    public function get_returning_customers_by_date($date)
    {
        global $wpdb;

        if (!defined('TGS_TABLE_LOCAL_LEDGER') || !defined('TGS_TABLE_LOCAL_LEDGER_PERSON')) {
            return array();
        }

        $ledger_table = TGS_TABLE_LOCAL_LEDGER;
        $person_table = TGS_TABLE_LOCAL_LEDGER_PERSON;

        // Get customers who bought today
        $today_customers = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT local_ledger_person_id FROM {$ledger_table}
                WHERE DATE(created_at) = %s
                  AND local_ledger_type IN (%d, %d)
                  AND is_deleted = 0
                  AND local_ledger_person_id IS NOT NULL",
                $date,
                TGS_LEDGER_TYPE_SALE_ORDER,
                TGS_LEDGER_TYPE_SALE
            )
        );

        if (empty($today_customers)) {
            return array();
        }

        $ids_placeholder = implode(',', array_map('intval', $today_customers));

        // Kiểm tra lại sau khi implode
        if (empty($ids_placeholder)) {
            return array();
        }

        // Count how many had previous purchases
        return $wpdb->get_results(
            "SELECT p.*, COUNT(DISTINCT l.local_ledger_id) as previous_orders
            FROM {$person_table} p
            INNER JOIN {$ledger_table} l ON p.local_ledger_person_id = l.local_ledger_person_id
            WHERE p.local_ledger_person_id IN ({$ids_placeholder})
              AND l.local_ledger_type IN (" . TGS_LEDGER_TYPE_SALE_ORDER . ", " . TGS_LEDGER_TYPE_SALE . ")
              AND l.is_deleted = 0
              AND DATE(l.created_at) < '{$date}'
            GROUP BY p.local_ledger_person_id
            HAVING previous_orders > 0");
    }

    /**
     * Get inventory summary for all products
     */
    public function get_inventory_summary()
    {
        global $wpdb;

        if (!defined('TGS_TABLE_LOCAL_PRODUCT_NAME') || !defined('TGS_TABLE_LOCAL_PRODUCT_LOT')) {
            return array();
        }

        $product_table = TGS_TABLE_LOCAL_PRODUCT_NAME;
        $lot_table = TGS_TABLE_LOCAL_PRODUCT_LOT;

        // Get tracking products with lot count
        $tracking_products = $wpdb->get_results(
            "SELECT
                p.local_product_name_id,
                p.local_product_name,
                p.local_product_price,
                p.local_product_tag,
                p.local_product_is_tracking,
                COUNT(l.local_product_lot_id) as lot_count,
                SUM(CASE WHEN l.exp_date < CURDATE() THEN 1 ELSE 0 END) as expired_count,
                SUM(CASE WHEN l.exp_date >= CURDATE() AND l.exp_date <= DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) as expiring_7days,
                SUM(CASE WHEN l.exp_date >= CURDATE() AND l.exp_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 1 ELSE 0 END) as expiring_30days
            FROM {$product_table} p
            LEFT JOIN {$lot_table} l ON p.local_product_name_id = l.local_product_name_id
                AND l.local_product_lot_is_active = 1
                AND l.is_deleted = 0
            WHERE p.local_product_is_tracking = 1
              AND p.is_deleted = 0
            GROUP BY p.local_product_name_id");

        // Get non-tracking products with quantity
        $non_tracking_products = $wpdb->get_results(
            "SELECT
                local_product_name_id,
                local_product_name,
                local_product_price,
                local_product_tag,
                local_product_is_tracking,
                local_product_quantity_no_tracking as quantity
            FROM {$product_table}
            WHERE local_product_is_tracking = 0
              AND is_deleted = 0");

        return array(
            'tracking'     => $tracking_products,
            'non_tracking' => $non_tracking_products,
        );
    }

    /**
     * Get max ID from array
     */
    private function get_max_id($items, $id_field)
    {
        if (empty($items)) {
            return 0;
        }

        $max = 0;
        foreach ($items as $item) {
            if (isset($item[$id_field]) && $item[$id_field] > $max) {
                $max = $item[$id_field];
            }
        }

        return $max;
    }
}

