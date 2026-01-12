<?php
/**
 * Inventory Roll-up Calculator Class
 * Tính toán và cập nhật dữ liệu tồn kho theo ngày
 *
 * @package TGS_Sync_Roll_Up
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Inventory_Roll_Up_Calculator
{
    /**
     * Database instance
     */
    private $database;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->database = new TGS_Sync_Roll_Up_Database();
    }

    /**
     * Tính toán inventory roll_up cho một ngày cụ thể
     * Logic: Inventory hôm nay = Inventory hôm qua + Phát sinh hôm nay
     *
     * @param int $blog_id Blog ID
     * @param string $date Ngày cần tính (Y-m-d)
     * @return array Dữ liệu inventory roll_up
     */
    public function calculate_daily_inventory_roll_up($blog_id, $date)
    {
        global $wpdb;

        // Parse ngày
        $date_parts = explode('-', $date);
        $year = intval($date_parts[0]);
        $month = intval($date_parts[1]);
        $day = intval($date_parts[2]);

        // Bước 1: Lấy inventory của ngày hôm qua làm base
        $yesterday = date('Y-m-d', strtotime($date . ' -1 day'));
        $yesterday_inventory = $this->get_inventory_by_date($blog_id, $yesterday);

        // Bước 2: Lấy các ledger phát sinh trong ngày (type 1, 2, 6)
        $ledger_table = defined('TGS_TABLE_LOCAL_LEDGER') ? TGS_TABLE_LOCAL_LEDGER : $wpdb->prefix . 'local_ledger';
        $ledgers = $wpdb->get_results($wpdb->prepare(
            "SELECT local_ledger_id, local_ledger_type
             FROM {$ledger_table}
             WHERE DATE(created_at) = %s
               AND is_deleted = 0
               AND is_croned = 0
               AND local_ledger_type IN (1, 2, 6)
             ORDER BY local_ledger_id ASC",
            $date
        ));

        if (empty($ledgers)) {
            // Không có phát sinh, copy ngày hôm qua sang hôm nay
            return $this->copy_yesterday_inventory($yesterday_inventory, $date, $day, $month, $year, $blog_id);
        }

        // Bước 3: Lấy ledger IDs
        $ledger_ids = array_column($ledgers, 'local_ledger_id');

        // Bước 4: Lấy ledger items từ các ledger phát sinh
        $item_table = defined('TGS_TABLE_LOCAL_LEDGER_ITEM') ? TGS_TABLE_LOCAL_LEDGER_ITEM : $wpdb->prefix . 'local_ledger_item';
        $ledger_ids_str = implode(',', array_map('intval', $ledger_ids));

        $items = $wpdb->get_results(
            "SELECT
                li.local_ledger_id,
                li.local_product_name_id,
                li.global_product_name_id,
                li.quantity,
                li.price,
                li.local_ledger_item_tax_amount
             FROM {$item_table} li
             WHERE li.local_ledger_id IN ({$ledger_ids_str})
               AND (li.is_deleted IS NULL OR li.is_deleted = 0)"
        );

        // Bước 5: Tạo map ledger_id => ledger_type
        $ledger_type_map = array();
        foreach ($ledgers as $ledger) {
            $ledger_type_map[$ledger->local_ledger_id] = intval($ledger->local_ledger_type);
        }

        // Bước 6: Tính toán inventory cho từng product
        $inventory_data = array();

        // Khởi tạo từ inventory hôm qua
        foreach ($yesterday_inventory as $product_id => $yesterday_data) {
            $inventory_data[$product_id] = array(
                'blog_id' => $blog_id,
                'local_product_name_id' => $product_id,
                'global_product_name_id' => $yesterday_data['global_product_name_id'],
                'roll_up_date' => $date,
                'roll_up_day' => $day,
                'roll_up_month' => $month,
                'roll_up_year' => $year,
                'inventory_qty' => $yesterday_data['inventory_qty'],
                'inventory_value' => $yesterday_data['inventory_value'],
                'meta' => array(),
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            );
        }

        // Xử lý các phát sinh trong ngày
        foreach ($items as $item) {
            $product_id = $item->local_product_name_id;
            $ledger_type = $ledger_type_map[$item->local_ledger_id] ?? 0;
            $quantity = floatval($item->quantity);
            $price = floatval($item->price);
            $tax_amount = floatval($item->local_ledger_item_tax_amount ?? 0);
            $value = ($quantity * $price) + $tax_amount;

            // Khởi tạo nếu chưa có trong inventory_data
            if (!isset($inventory_data[$product_id])) {
                $inventory_data[$product_id] = array(
                    'blog_id' => $blog_id,
                    'local_product_name_id' => $product_id,
                    'global_product_name_id' => $item->global_product_name_id,
                    'roll_up_date' => $date,
                    'roll_up_day' => $day,
                    'roll_up_month' => $month,
                    'roll_up_year' => $year,
                    'inventory_qty' => 0,
                    'inventory_value' => 0,
                    'meta' => array(),
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                );
            }

            // Tính toán theo type
            if ($ledger_type == 1) {
                // Type 1: Nhập nội bộ - CỘNG
                $inventory_data[$product_id]['inventory_qty'] += $quantity;
                $inventory_data[$product_id]['inventory_value'] += $value;
            } elseif ($ledger_type == 2 || $ledger_type == 6) {
                // Type 2: Xuất nội bộ, Type 6: Hàng hỏng - TRỪ
                $inventory_data[$product_id]['inventory_qty'] -= $quantity;
                $inventory_data[$product_id]['inventory_value'] -= $value;
            }
        }

        // Bước 7: Đánh dấu các ledger đã xử lý
        if (!empty($ledger_ids)) {
            $wpdb->query(
                "UPDATE {$ledger_table}
                 SET is_croned = 1
                 WHERE local_ledger_id IN ({$ledger_ids_str})"
            );
        }

        return array_values($inventory_data);
    }

    /**
     * Lấy inventory của một ngày
     *
     * @param int $blog_id Blog ID
     * @param string $date Ngày (Y-m-d)
     * @return array Map: product_id => inventory_data
     */
    private function get_inventory_by_date($blog_id, $date)
    {
        global $wpdb;

        $table = $wpdb->prefix . 'inventory_roll_up';
        $date_parts = explode('-', $date);
        $day = intval($date_parts[2]);
        $month = intval($date_parts[1]);
        $year = intval($date_parts[0]);

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT
                local_product_name_id,
                global_product_name_id,
                inventory_qty,
                inventory_value
             FROM {$table}
             WHERE blog_id = %d
               AND roll_up_day = %d
               AND roll_up_month = %d
               AND roll_up_year = %d",
            $blog_id,
            $day,
            $month,
            $year
        ));

        $inventory = array();
        foreach ($results as $row) {
            $inventory[$row->local_product_name_id] = array(
                'global_product_name_id' => $row->global_product_name_id,
                'inventory_qty' => floatval($row->inventory_qty),
                'inventory_value' => floatval($row->inventory_value),
            );
        }

        return $inventory;
    }

    /**
     * Copy inventory hôm qua sang hôm nay (khi không có phát sinh)
     *
     * @param array $yesterday_inventory Inventory hôm qua
     * @param string $date Ngày hôm nay
     * @param int $day Ngày
     * @param int $month Tháng
     * @param int $year Năm
     * @param int $blog_id Blog ID
     * @return array Inventory data
     */
    private function copy_yesterday_inventory($yesterday_inventory, $date, $day, $month, $year, $blog_id)
    {
        $inventory_data = array();

        foreach ($yesterday_inventory as $product_id => $yesterday_data) {
            $inventory_data[] = array(
                'blog_id' => $blog_id,
                'local_product_name_id' => $product_id,
                'global_product_name_id' => $yesterday_data['global_product_name_id'],
                'roll_up_date' => $date,
                'roll_up_day' => $day,
                'roll_up_month' => $month,
                'roll_up_year' => $year,
                'inventory_qty' => $yesterday_data['inventory_qty'],
                'inventory_value' => $yesterday_data['inventory_value'],
                'meta' => array(),
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            );
        }

        return $inventory_data;
    }

    /**
     * Lưu inventory roll_up vào database
     * Sử dụng ON DUPLICATE KEY UPDATE để tối ưu hiệu suất
     *
     * @param array $data Dữ liệu inventory roll_up
     * @return int|false ID của record được lưu hoặc false nếu lỗi
     */
    public function save_inventory_roll_up($data)
    {
        global $wpdb;

        $table = $wpdb->prefix . 'inventory_roll_up';
        $updated_at = current_time('mysql');

        // Chuẩn bị dữ liệu
        $blog_id = intval($data['blog_id']);
        $local_product_name_id = intval($data['local_product_name_id']);
        $global_product_name_id = isset($data['global_product_name_id']) ? intval($data['global_product_name_id']) : 'NULL';
        $roll_up_date = $wpdb->prepare('%s', $data['roll_up_date']);
        $roll_up_day = intval($data['roll_up_day']);
        $roll_up_month = intval($data['roll_up_month']);
        $roll_up_year = intval($data['roll_up_year']);
        $inventory_qty = floatval($data['inventory_qty']);
        $inventory_value = floatval($data['inventory_value']);
        $meta = !empty($data['meta']) ? $wpdb->prepare('%s', json_encode($data['meta'])) : 'NULL';
        $created_at = $wpdb->prepare('%s', $data['created_at']);
        $updated_at_prepared = $wpdb->prepare('%s', $updated_at);

        // Xây dựng câu query với ON DUPLICATE KEY UPDATE
        $sql = "INSERT INTO {$table}
                (blog_id, local_product_name_id, global_product_name_id, roll_up_date,
                 roll_up_day, roll_up_month, roll_up_year, inventory_qty, inventory_value,
                 meta, created_at, updated_at)
                VALUES
                ({$blog_id}, {$local_product_name_id}, {$global_product_name_id}, {$roll_up_date},
                 {$roll_up_day}, {$roll_up_month}, {$roll_up_year}, {$inventory_qty}, {$inventory_value},
                 {$meta}, {$created_at}, {$updated_at_prepared})
                ON DUPLICATE KEY UPDATE
                    global_product_name_id = VALUES(global_product_name_id),
                    inventory_qty = VALUES(inventory_qty),
                    inventory_value = VALUES(inventory_value),
                    meta = VALUES(meta),
                    updated_at = {$updated_at_prepared}";

        $result = $wpdb->query($sql);

        if ($result === false) {
            return false;
        }

        // Trả về ID: nếu là insert mới thì dùng insert_id, nếu update thì lấy ID từ DB
        if ($wpdb->insert_id > 0) {
            return $wpdb->insert_id;
        } else {
            // Trường hợp update, lấy ID từ database
            return $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table}
                 WHERE blog_id = %d
                   AND roll_up_day = %d
                   AND roll_up_month = %d
                   AND roll_up_year = %d
                   AND local_product_name_id = %d",
                $blog_id,
                $roll_up_day,
                $roll_up_month,
                $roll_up_year,
                $local_product_name_id
            ));
        }
    }

    /**
     * Lấy inventory summary theo khoảng thời gian
     *
     * @param int $blog_id Blog ID
     * @param string $start_date Ngày bắt đầu
     * @param string $end_date Ngày kết thúc
     * @return array Inventory summary
     */
    public function get_inventory_summary($blog_id, $start_date, $end_date)
    {
        global $wpdb;

        $table = $wpdb->prefix . 'inventory_roll_up';

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT
                roll_up_date,
                SUM(inventory_qty) as total_qty,
                SUM(inventory_value) as total_value
             FROM {$table}
             WHERE blog_id = %d
               AND roll_up_date BETWEEN %s AND %s
             GROUP BY roll_up_date
             ORDER BY roll_up_date ASC",
            $blog_id,
            $start_date,
            $end_date
        ));

        return $results;
    }

    /**
     * Lấy inventory hiện tại của một sản phẩm
     *
     * @param int $blog_id Blog ID
     * @param int $product_id Product ID
     * @param string $date Ngày (mặc định là hôm nay)
     * @return array|null Inventory data
     */
    public function get_product_inventory($blog_id, $product_id, $date = null)
    {
        global $wpdb;

        if (empty($date)) {
            $date = current_time('Y-m-d');
        }

        $table = $wpdb->prefix . 'inventory_roll_up';
        $date_parts = explode('-', $date);
        $day = intval($date_parts[2]);
        $month = intval($date_parts[1]);
        $year = intval($date_parts[0]);

        return $wpdb->get_row($wpdb->prepare(
            "SELECT *
             FROM {$table}
             WHERE blog_id = %d
               AND local_product_name_id = %d
               AND roll_up_day = %d
               AND roll_up_month = %d
               AND roll_up_year = %d",
            $blog_id,
            $product_id,
            $day,
            $month,
            $year
        ), ARRAY_A);
    }
}
