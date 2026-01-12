<?php
/**
 * Roll-up Calculator Class
 * Tính toán và cập nhật dữ liệu thống kê roll_up theo ngày
 *
 * @package TGS_Sync_Roll_Up
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Roll_Up_Calculator
{
    /**
     * Data collector instance
     */
    private $data_collector;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->data_collector = new TGS_Sync_Roll_Up_Data_Collector();
    }

    /**
     * Tính toán roll_up cho một ngày cụ thể
     *
     * @param int $blog_id Blog ID
     * @param string $date Ngày cần tính (Y-m-d)
     * @return array Dữ liệu roll_up
     */
    public function calculate_daily_roll_up($blog_id, $date)
    {
        global $wpdb;

        // Lấy dữ liệu ledger theo ngày
        $ledgers = $this->data_collector->get_ledgers_by_date($date);

        $ledgerIds = array_column($ledgers, 'local_ledger_id');

        $childrenLedgers = $this->data_collector->get_children_ledgers($ledgerIds);
        $childrenLedgerIds = array_column($childrenLedgers, 'local_ledger_id');

        $ledgerItems = $this->data_collector->get_ledger_items($childrenLedgerIds);

        // Parse ngày/tháng/năm để GROUP BY linh hoạt
        $date_parts = explode('-', $date);
        $year = intval($date_parts[0]);
        $month = intval($date_parts[1]);
        $day = intval($date_parts[2]);

        // Tạo mapping: parent ledger ID => ledger type
        $parent_type_map = [];
        foreach ($ledgers as $ledger) {
            $parent_type_map[$ledger->local_ledger_id] = intval($ledger->local_ledger_type);
        }

        // Tạo mapping: children ledger ID => parent ledger ID
        $child_to_parent_map = [];
        foreach ($childrenLedgers as $child) {
            $child_to_parent_map[$child->local_ledger_id] = intval($child->local_ledger_parent_id);
        }

        $roll_up_data = [];

        foreach($ledgerItems as $item) {
            // Lấy type từ parent ledger
            $child_ledger_id = $item->local_ledger_id;
            $parent_ledger_id = isset($child_to_parent_map[$child_ledger_id]) ? $child_to_parent_map[$child_ledger_id] : 0;
            $ledger_type = isset($parent_type_map[$parent_ledger_id]) ? $parent_type_map[$parent_ledger_id] : 0;

            // Lấy list_product_lots từ item (JSON array)
            $lot_ids = [];
            if (!empty($item->list_product_lots)) {
                $decoded = json_decode($item->list_product_lots, true);
                if (is_array($decoded)) {
                    $lot_ids = array_map('intval', $decoded);
                }
            }

            // Tạo unique key bao gồm cả type để group đúng
            $key = $item->local_product_name_id . '_' . $ledger_type;
            
            $tax_amount = floatval($item->local_ledger_item_tax_amount ?? 0);
            $amount_before_tax = $item->quantity * $item->price;
            $amount_after_tax = $amount_before_tax + $tax_amount;

            if (isset($roll_up_data[$key])) {
                $roll_up_data[$key]['amount_after_tax'] += $amount_after_tax;
                $roll_up_data[$key]['tax'] += $tax_amount;
                $roll_up_data[$key]['quantity'] += $item->quantity;

                // Merge lot_ids (không trùng lặp)
                if (!empty($lot_ids)) {
                    $existing_lots = isset($roll_up_data[$key]['lot_ids']) ? $roll_up_data[$key]['lot_ids'] : [];
                    $roll_up_data[$key]['lot_ids'] = array_values(array_unique(array_merge($existing_lots, $lot_ids)));
                }

                continue;
            }

            $roll_up_data[$key] = [
                'blog_id' => $blog_id,
                'roll_up_date' => $date,
                'roll_up_day' => $day,
                'roll_up_month' => $month,
                'roll_up_year' => $year,
                'local_product_name_id' => $item->local_product_name_id,
                'amount_after_tax' => $amount_after_tax,
                'tax' => $tax_amount,
                'quantity' => $item->quantity,
                'type' => $ledger_type,
                'lot_ids' => $lot_ids,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ];
        }

        // Update is_croned = 1 cho các ledger đã được xử lý
        if (!empty($ledgerIds) && defined('TGS_TABLE_LOCAL_LEDGER')) {
            $ledger_table = TGS_TABLE_LOCAL_LEDGER;
            $ledger_ids_str = implode(',', array_map('intval', $ledgerIds));

            $wpdb->query(
                "UPDATE {$ledger_table}
                 SET is_croned = 1
                 WHERE local_ledger_id IN ({$ledger_ids_str})"
            );
        }

        return $roll_up_data;
    }

    /**
     * Tính toán từ dữ liệu ledgers
     *
     * @param array $ledgers Danh sách ledgers
     * @param array &$roll_up_data Dữ liệu roll_up để cập nhật
     * @param string $date Ngày
     */
    private function calculate_from_ledgers($ledgers, &$roll_up_data, $date)
    {
        global $wpdb;

        foreach ($ledgers as $ledger) {
            $ledger_type = intval($ledger->local_ledger_type);
            $ledger_id = $ledger->local_ledger_id;
            $ledger_total = floatval($ledger->local_ledger_total_amount ?? 0);

            // Đếm số lượng đơn theo loại
            switch ($ledger_type) {
                case TGS_LEDGER_TYPE_PURCHASE: // 9 - Mua hàng
                    $roll_up_data['count_purchase_orders']++;
                    break;

                case TGS_LEDGER_TYPE_SALES: // 10 - Bán hàng
                    $roll_up_data['count_sales_orders']++;
                    // Tính doanh thu
                    $roll_up_data['revenue_total'] += $ledger_total;
                    $this->calculate_sales_revenue($ledger_id, $roll_up_data);
                    break;

                case TGS_LEDGER_TYPE_RETURN: // 11 - Trả hàng
                    $roll_up_data['count_return_orders']++;
                    break;

                case TGS_LEDGER_TYPE_DAMAGE: // 6 - Hàng hỏng
                    $roll_up_data['count_damaged_orders']++;
                    break;

                case TGS_LEDGER_TYPE_IMPORT: // 1 - Nhập nội bộ
                    $roll_up_data['count_internal_import']++;
                    break;

                case TGS_LEDGER_TYPE_EXPORT: // 2 - Xuất nội bộ
                    $roll_up_data['count_internal_export']++;
                    break;

                case TGS_LEDGER_TYPE_RECEIPT: // 7 - Thu tiền
                    $roll_up_data['count_receipt_orders']++;
                    $roll_up_data['total_receipt_amount'] += $ledger_total;
                    break;

                case TGS_LEDGER_TYPE_PAYMENT: // 8 - Chi tiền
                    $roll_up_data['count_payment_orders']++;
                    $roll_up_data['total_payment_amount'] += $ledger_total;
                    break;
            }
        }
    }

    /**
     * Tính doanh thu từ đơn bán hàng theo SP chiến lược/thường
     *
     * @param int $ledger_id Ledger ID
     * @param array &$roll_up_data Dữ liệu roll_up
     */
    private function calculate_sales_revenue($ledger_id, &$roll_up_data)
    {
        global $wpdb;

        if (!defined('TGS_TABLE_LOCAL_LEDGER_ITEM') || !defined('TGS_TABLE_LOCAL_PRODUCT_NAME')) {
            return;
        }

        $item_table = TGS_TABLE_LOCAL_LEDGER_ITEM;
        $product_table = TGS_TABLE_LOCAL_PRODUCT_NAME;

        // Lấy chi tiết items với product tag
        $items = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                li.quantity,
                li.price,
                COALESCE(pn.local_product_tag, 0) as product_tag
             FROM {$item_table} li
             LEFT JOIN {$product_table} pn ON li.local_product_name_id = pn.local_product_name_id
             WHERE li.local_ledger_id = %d
             AND (li.is_deleted IS NULL OR li.is_deleted = 0)",
            $ledger_id
        ));

        foreach ($items as $item) {
            $item_total = floatval($item->quantity) * floatval($item->price);
            $tag = intval($item->product_tag);

            if ($tag == TGS_PRODUCT_TAG_STRATEGIC) {
                $roll_up_data['revenue_strategic_products'] += $item_total;
            } else {
                $roll_up_data['revenue_normal_products'] += $item_total;
            }
        }
    }

    /**
     * Tính toán khách hàng mới và quay lại
     *
     * @param string $date Ngày
     * @param array &$roll_up_data Dữ liệu roll_up
     */
    private function calculate_customers($date, &$roll_up_data)
    {
        global $wpdb;

        if (!defined('TGS_TABLE_LOCAL_LEDGER')) {
            return;
        }

        $ledger_table = TGS_TABLE_LOCAL_LEDGER;

        // Lấy tất cả customer ID trong ngày hôm nay (từ phiếu bán hàng)
        $today_customers = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT local_ledger_person_id 
             FROM {$ledger_table} 
             WHERE DATE(created_at) = %s 
             AND local_ledger_type = %d
             AND local_ledger_person_id IS NOT NULL
             AND (is_deleted IS NULL OR is_deleted = 0)",
            $date,
            TGS_LEDGER_TYPE_SALES
        ));

        $roll_up_data['count_total_customers'] = count($today_customers);

        if (empty($today_customers)) {
            return;
        }

        // Đếm khách mới (chưa mua hàng trước ngày hôm nay)
        $person_ids = implode(',', array_map('intval', $today_customers));
        
        $returning_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(DISTINCT local_ledger_person_id) 
             FROM {$ledger_table} 
             WHERE local_ledger_person_id IN ({$person_ids})
             AND DATE(created_at) < %s
             AND local_ledger_type = %d
             AND (is_deleted IS NULL OR is_deleted = 0)",
            $date,
            TGS_LEDGER_TYPE_SALES
        ));

        $roll_up_data['count_returning_customers'] = intval($returning_count);
        $roll_up_data['count_new_customers'] = $roll_up_data['count_total_customers'] - $roll_up_data['count_returning_customers'];
    }

    /**
     * Tính toán HSD summary
     *
     * @param string $date Ngày hiện tại
     * @return array Expiry summary
     */
    public function calculate_expiry_summary($date)
    {
        global $wpdb;

        if (!defined('TGS_TABLE_LOCAL_PRODUCT_LOT')) {
            return array(
                'by_range' => array(),
                'total_with_expiry' => 0,
                'calculated_at' => current_time('mysql'),
            );
        }

        $lot_table = TGS_TABLE_LOCAL_PRODUCT_LOT;

        // Đếm số lot theo khoảng HSD (chỉ đếm lot, không có quantity vì bảng không có cột quantity)
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT 
                CASE 
                    WHEN exp_date < %s THEN 'expired'
                    WHEN DATEDIFF(exp_date, %s) BETWEEN 0 AND 7 THEN '0_7_days'
                    WHEN DATEDIFF(exp_date, %s) BETWEEN 8 AND 30 THEN '8_30_days'
                    WHEN DATEDIFF(exp_date, %s) BETWEEN 31 AND 90 THEN '31_90_days'
                    WHEN DATEDIFF(exp_date, %s) > 90 THEN 'over_90_days'
                    ELSE 'no_expiry'
                END as expiry_range,
                COUNT(*) as lot_count
             FROM {$lot_table}
             WHERE (local_product_lot_is_active = 1 OR local_product_lot_is_active IS NULL)
             AND (is_deleted IS NULL OR is_deleted = 0)
             AND exp_date IS NOT NULL
             GROUP BY expiry_range",
            $date, $date, $date, $date, $date
        ));

        $by_range = array();
        $total = 0;

        foreach ($results as $row) {
            $by_range[$row->expiry_range] = array(
                'lot_count' => intval($row->lot_count),
                'quantity' => 0, // Không có cột quantity trong bảng
            );
            $total += intval($row->lot_count);
        }

        return array(
            'by_range' => $by_range,
            'total_with_expiry' => $total,
            'calculated_at' => current_time('mysql'),
        );
    }

    /**
     * Lưu roll_up vào database
     *
     * @param array $data Dữ liệu roll_up
     * @param bool $overwrite Nếu true, ghi đè dữ liệu cũ; nếu false, cộng dồn amount và quantity (mặc định)
     * @return int|false Roll up ID hoặc false nếu lỗi
     */
    public function save_roll_up($data, $overwrite = false)
    {
        global $wpdb;

        if (!defined('TGS_TABLE_ROLL_UP')) {
            return false;
        }

        // Dùng $wpdb->prefix để lấy đúng bảng sau khi switch_to_blog
        $table = $wpdb->prefix . 'product_roll_up';

        // Đảm bảo có created_at và updated_at
        $current_time = current_time('mysql');
        if (!isset($data['created_at'])) {
            $data['created_at'] = $current_time;
        }
        $data['updated_at'] = $current_time;

        // Xử lý lot_ids riêng biệt (sẽ lưu vào cột meta)
        $new_lot_ids = [];
        if (isset($data['lot_ids'])) {
            $new_lot_ids = is_array($data['lot_ids']) ? $data['lot_ids'] : [];
            unset($data['lot_ids']); // Xóa khỏi data vì không phải cột thực tế
        }

        // Kiểm tra xem record đã tồn tại chưa để merge lot_ids
        $existing_meta = null;
        if (!empty($new_lot_ids)) {
            $blog_id = $data['blog_id'];
            $roll_up_day = $data['roll_up_day'];
            $roll_up_month = $data['roll_up_month'];
            $roll_up_year = $data['roll_up_year'];
            $local_product_name_id = $data['local_product_name_id'];
            $type = isset($data['type']) ? $data['type'] : 0;

            $existing_meta = $wpdb->get_var($wpdb->prepare(
                "SELECT meta FROM {$table}
                 WHERE blog_id = %d
                 AND roll_up_day = %d
                 AND roll_up_month = %d
                 AND roll_up_year = %d
                 AND local_product_name_id = %d
                 AND type = %d",
                $blog_id,
                $roll_up_day,
                $roll_up_month,
                $roll_up_year,
                $local_product_name_id,
                $type
            ));
        }

        // Merge lot_ids với meta cũ (nếu có)
        $merged_lot_ids = $new_lot_ids;
        if (!empty($existing_meta)) {
            $existing_data = json_decode($existing_meta, true);
            if (is_array($existing_data) && isset($existing_data['lot_ids']) && is_array($existing_data['lot_ids'])) {
                $merged_lot_ids = array_values(array_unique(array_merge($existing_data['lot_ids'], $new_lot_ids)));
            }
        }

        // Tạo meta JSON
        if (!empty($merged_lot_ids)) {
            $data['meta'] = json_encode(['lot_ids' => $merged_lot_ids]);
        }

        // Các cột sẽ cộng dồn khi duplicate (nếu $overwrite = false)
        $cumulative_columns = $overwrite ? array() : array('amount_after_tax', 'tax', 'quantity');

        // Tạo danh sách columns và values
        $columns = array();
        $values = array();
        $update_parts = array();
        $placeholders = array();

        foreach ($data as $column => $value) {
            $columns[] = "`{$column}`";
            $values[] = $value;

            // Tạo placeholder dựa trên kiểu dữ liệu
            if (is_int($value)) {
                $placeholders[] = '%d';
            } elseif (is_float($value)) {
                $placeholders[] = '%f';
            } else {
                $placeholders[] = '%s';
            }

            // Xử lý update khi duplicate
            if ($column === 'created_at') {
                // Không update created_at
                continue;
            } elseif (in_array($column, $cumulative_columns)) {
                // Cộng dồn cho amount và quantity
                $update_parts[] = "`{$column}` = `{$column}` + VALUES(`{$column}`)";
            } else {
                // Thay thế giá trị cho các cột khác
                $update_parts[] = "`{$column}` = VALUES(`{$column}`)";
            }
        }

        // Tạo SQL query với ON DUPLICATE KEY UPDATE
        $sql = sprintf(
            "INSERT INTO {$table} (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s",
            implode(', ', $columns),
            implode(', ', $placeholders),
            implode(', ', $update_parts)
        );

        // Execute query với prepare
        $prepared = $wpdb->prepare($sql, $values);
        $result = $wpdb->query($prepared);

        if ($result === false) {
            return false;
        }

        // Trả về insert_id (nếu insert mới) hoặc lấy ID của record đã update
        if ($wpdb->insert_id > 0) {
            return $wpdb->insert_id;
        } else {
            // Lấy ID của record đã update
            $blog_id = $data['blog_id'];
            $roll_up_day = $data['roll_up_day'];
            $roll_up_month = $data['roll_up_month'];
            $roll_up_year = $data['roll_up_year'];
            $local_product_name_id = $data['local_product_name_id'];
            $type = isset($data['type']) ? $data['type'] : 0;

            return $wpdb->get_var($wpdb->prepare(
                "SELECT roll_up_id FROM {$table}
                 WHERE blog_id = %d
                 AND roll_up_day = %d
                 AND roll_up_month = %d
                 AND roll_up_year = %d
                 AND local_product_name_id = %d
                 AND type = %d",
                $blog_id,
                $roll_up_day,
                $roll_up_month,
                $roll_up_year,
                $local_product_name_id,
                $type
            ));
        }
    }

    /**
     * Lấy roll_up theo blog_id và ngày
     *
     * @param int $blog_id Blog ID
     * @param string $date Ngày
     * @return object|null Roll up data
     */
    public function get_roll_up($blog_id, $date)
    {
        global $wpdb;

        if (!defined('TGS_TABLE_ROLL_UP')) {
            return null;
        }

        // Dùng $wpdb->prefix để lấy đúng bảng sau khi switch_to_blog
        $table = $wpdb->prefix . 'product_roll_up';

        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE blog_id = %d AND roll_up_date = %s",
            $blog_id,
            $date
        ));
    }

    /**
     * Lấy roll_up meta theo blog_id và ngày
     *
     * @param int $blog_id Blog ID
     * @param string $date Ngày
     * @return object|null Meta data với own_data và children_summary đã decode
     */
    public function get_roll_up_meta($blog_id, $date)
    {
        global $wpdb;

        if (!defined('TGS_TABLE_ROLL_UP_META')) {
            return null;
        }

        $roll_up_table = $wpdb->prefix . 'product_roll_up';
        $meta_table = $wpdb->prefix . 'roll_up_meta';

        $meta = $wpdb->get_row($wpdb->prepare(
            "SELECT rm.* 
             FROM {$meta_table} rm
             INNER JOIN {$roll_up_table} r ON rm.roll_up_id = r.roll_up_id
             WHERE r.blog_id = %d AND r.roll_up_date = %s",
            $blog_id,
            $date
        ));

        if ($meta) {
            // Decode JSON fields
            $meta->own_data = !empty($meta->own_data) ? json_decode($meta->own_data, true) : array();
            $meta->children_summary = !empty($meta->children_summary) ? json_decode($meta->children_summary, true) : array();
            $meta->sync_log = !empty($meta->sync_log) ? json_decode($meta->sync_log, true) : array();
        }

        return $meta;
    }

    /**
     * Tính roll_up meta (chi tiết bổ sung)
     *
     * @param int $blog_id Blog ID
     * @param string $date Ngày
     * @return array Meta data
     */
    public function calculate_roll_up_meta($blog_id, $date)
    {
        return array(
            'revenue_by_category' => json_encode(array()),
            'product_summary' => json_encode(array()),
            'customer_stats' => json_encode(array()),
            'returning_rate_30days' => 0,
            'sync_log' => json_encode(array()),
            'children_summary' => json_encode(array()),
        );
    }

    /**
     * Lưu roll_up meta
     *
     * @param int $roll_up_id Roll up ID
     * @param int $blog_id Blog ID
     * @param string $date Ngày
     * @param array $meta_data Meta data
     * @return int|false Meta ID hoặc false
     */
    public function save_roll_up_meta($roll_up_id, $blog_id, $date, $meta_data)
    {
        global $wpdb;

        if (!defined('TGS_TABLE_ROLL_UP_META')) {
            return false;
        }

        // Dùng $wpdb->prefix để lấy đúng bảng sau khi switch_to_blog
        $table = $wpdb->prefix . 'roll_up_meta';

        // Kiểm tra đã có chưa
        $existing_id = $wpdb->get_var($wpdb->prepare(
            "SELECT roll_up_meta_id FROM {$table} WHERE roll_up_id = %d",
            $roll_up_id
        ));

        // Helper function để convert giá trị thành JSON hợp lệ hoặc null
        $to_json = function($value) {
            if ($value === null || $value === '') {
                return null;
            }
            if (is_string($value)) {
                // Đã là JSON string
                return $value;
            }
            if (is_array($value) || is_object($value)) {
                return json_encode($value);
            }
            return null;
        };

        $data = array(
            'roll_up_id' => $roll_up_id,
            'blog_id' => $blog_id,
            'roll_up_date' => $date,
            'own_data' => $to_json(isset($meta_data['own_data']) ? $meta_data['own_data'] : null),
            'revenue_by_category' => $to_json(isset($meta_data['revenue_by_category']) ? $meta_data['revenue_by_category'] : null),
            'product_summary' => $to_json(isset($meta_data['product_summary']) ? $meta_data['product_summary'] : null),
            'customer_stats' => $to_json(isset($meta_data['customer_stats']) ? $meta_data['customer_stats'] : null),
            'returning_rate_30days' => isset($meta_data['returning_rate_30days']) ? floatval($meta_data['returning_rate_30days']) : null,
            'sync_log' => $to_json(isset($meta_data['sync_log']) ? $meta_data['sync_log'] : null),
            'children_summary' => $to_json(isset($meta_data['children_summary']) ? $meta_data['children_summary'] : null),
            'updated_at' => current_time('mysql'),
        );

        if ($existing_id) {
            $wpdb->update($table, $data, array('roll_up_meta_id' => $existing_id));
            return $existing_id;
        } else {
            $data['created_at'] = current_time('mysql');
            $wpdb->insert($table, $data);
            return $wpdb->insert_id;
        }
    }

    /**
     * Lấy monthly summary bằng GROUP BY
     *
     * @param int $blog_id Blog ID
     * @param string $month Tháng (Y-m)
     * @return object|null Monthly summary
     */
    public function get_monthly_summary($blog_id, $month)
    {
        global $wpdb;

        if (!defined('TGS_TABLE_ROLL_UP')) {
            return null;
        }

        $table = TGS_TABLE_ROLL_UP;
        $year_month = explode('-', $month);
        $year = intval($year_month[0]);
        $month_num = intval($year_month[1]);

        return $wpdb->get_row($wpdb->prepare(
            "SELECT 
                blog_id,
                roll_up_year,
                roll_up_month,
                SUM(revenue_total) as revenue_total,
                SUM(revenue_strategic_products) as revenue_strategic_products,
                SUM(revenue_normal_products) as revenue_normal_products,
                SUM(count_sales_orders) as count_sales_orders,
                SUM(count_purchase_orders) as count_purchase_orders,
                SUM(count_new_customers) as count_new_customers,
                SUM(count_returning_customers) as count_returning_customers,
                AVG(avg_order_value) as avg_order_value
             FROM {$table}
             WHERE blog_id = %d 
             AND roll_up_year = %d 
             AND roll_up_month = %d
             GROUP BY blog_id, roll_up_year, roll_up_month",
            $blog_id,
            $year,
            $month_num
        ));
    }

    /**
     * Lấy weekly summary
     *
     * @param int $blog_id Blog ID
     * @param string $start_date Ngày bắt đầu tuần
     * @param string $end_date Ngày kết thúc tuần
     * @return object|null Weekly summary
     */
    public function get_weekly_summary($blog_id, $start_date, $end_date)
    {
        global $wpdb;

        if (!defined('TGS_TABLE_ROLL_UP')) {
            return null;
        }

        $table = TGS_TABLE_ROLL_UP;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT 
                blog_id,
                SUM(revenue_total) as revenue_total,
                SUM(revenue_strategic_products) as revenue_strategic_products,
                SUM(revenue_normal_products) as revenue_normal_products,
                SUM(count_sales_orders) as count_sales_orders,
                SUM(count_new_customers) as count_new_customers,
                AVG(avg_order_value) as avg_order_value
             FROM {$table}
             WHERE blog_id = %d 
             AND roll_up_date BETWEEN %s AND %s
             GROUP BY blog_id",
            $blog_id,
            $start_date,
            $end_date
        ));
    }

    /**
     * Lấy yearly summary
     *
     * @param int $blog_id Blog ID
     * @param int $year Năm
     * @return object|null Yearly summary
     */
    public function get_yearly_summary($blog_id, $year)
    {
        global $wpdb;

        if (!defined('TGS_TABLE_ROLL_UP')) {
            return null;
        }

        $table = TGS_TABLE_ROLL_UP;

        return $wpdb->get_row($wpdb->prepare(
            "SELECT 
                blog_id,
                roll_up_year,
                SUM(revenue_total) as revenue_total,
                SUM(revenue_strategic_products) as revenue_strategic_products,
                SUM(revenue_normal_products) as revenue_normal_products,
                SUM(count_sales_orders) as count_sales_orders,
                SUM(count_new_customers) as count_new_customers,
                AVG(avg_order_value) as avg_order_value
             FROM {$table}
             WHERE blog_id = %d 
             AND roll_up_year = %d
             GROUP BY blog_id, roll_up_year",
            $blog_id,
            $year
        ));
    }

    /**
     * Lấy thống kê theo khoảng ngày
     *
     * @param int $blog_id Blog ID
     * @param string $from_date Ngày bắt đầu (Y-m-d)
     * @param string $to_date Ngày kết thúc (Y-m-d)
     * @return array|null Stats data
     */
    public function get_stats_by_date_range($blog_id, $from_date, $to_date)
    {
        global $wpdb;

        $table = $wpdb->prefix . 'product_roll_up';

        // Nếu cùng 1 ngày, lấy trực tiếp
        if ($from_date === $to_date) {
            $row = $this->get_roll_up($blog_id, $from_date);
            if ($row) {
                return array(
                    'revenue_total' => floatval($row->revenue_total),
                    'revenue_strategic_products' => floatval($row->revenue_strategic_products),
                    'revenue_normal_products' => floatval($row->revenue_normal_products),
                    'count_sales_orders' => intval($row->count_sales_orders),
                    'count_purchase_orders' => intval($row->count_purchase_orders),
                    'count_return_orders' => intval($row->count_return_orders),
                    'count_internal_import' => intval($row->count_internal_import),
                    'count_internal_export' => intval($row->count_internal_export),
                    'inventory_total_quantity' => floatval($row->inventory_total_quantity),
                    'inventory_total_value' => floatval($row->inventory_total_value),
                    'count_new_customers' => intval($row->count_new_customers),
                    'count_returning_customers' => intval($row->count_returning_customers),
                    'count_total_customers' => intval($row->count_total_customers),
                    'avg_order_value' => floatval($row->avg_order_value),
                );
            }
            return null;
        }

        // Khoảng nhiều ngày - SUM các cột cần cộng
        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT 
                SUM(revenue_total) as revenue_total,
                SUM(revenue_strategic_products) as revenue_strategic_products,
                SUM(revenue_normal_products) as revenue_normal_products,
                SUM(count_sales_orders) as count_sales_orders,
                SUM(count_purchase_orders) as count_purchase_orders,
                SUM(count_return_orders) as count_return_orders,
                SUM(count_internal_import) as count_internal_import,
                SUM(count_internal_export) as count_internal_export,
                SUM(count_new_customers) as count_new_customers,
                SUM(count_returning_customers) as count_returning_customers,
                SUM(count_total_customers) as count_total_customers
             FROM {$table}
             WHERE blog_id = %d 
             AND roll_up_date BETWEEN %s AND %s",
            $blog_id,
            $from_date,
            $to_date
        ));

        // Lấy tồn kho cuối kỳ (ngày cuối)
        $last_day = $wpdb->get_row($wpdb->prepare(
            "SELECT inventory_total_quantity, inventory_total_value
             FROM {$table}
             WHERE blog_id = %d AND roll_up_date <= %s
             ORDER BY roll_up_date DESC
             LIMIT 1",
            $blog_id,
            $to_date
        ));

        if ($result) {
            $total_orders = intval($result->count_sales_orders);
            $total_revenue = floatval($result->revenue_total);
            
            return array(
                'revenue_total' => $total_revenue,
                'revenue_strategic_products' => floatval($result->revenue_strategic_products),
                'revenue_normal_products' => floatval($result->revenue_normal_products),
                'count_sales_orders' => $total_orders,
                'count_purchase_orders' => intval($result->count_purchase_orders),
                'count_return_orders' => intval($result->count_return_orders),
                'count_internal_import' => intval($result->count_internal_import),
                'count_internal_export' => intval($result->count_internal_export),
                'inventory_total_quantity' => $last_day ? floatval($last_day->inventory_total_quantity) : 0,
                'inventory_total_value' => $last_day ? floatval($last_day->inventory_total_value) : 0,
                'count_new_customers' => intval($result->count_new_customers),
                'count_returning_customers' => intval($result->count_returning_customers),
                'count_total_customers' => intval($result->count_total_customers),
                'avg_order_value' => $total_orders > 0 ? ($total_revenue / $total_orders) : 0,
            );
        }

        return null;
    }

    /**
     * Tính tổng amount và quantity theo local_product_name_id từ bảng roll_up
     *
     * @param int $blog_id Blog ID (null = blog hiện tại)
     * @param string|null $date Ngày cụ thể (Y-m-d) hoặc null = tất cả ngày
     * @param array $options Tùy chọn bổ sung:
     *   - 'start_date': string - Ngày bắt đầu (Y-m-d)
     *   - 'end_date': string - Ngày kết thúc (Y-m-d)
     *   - 'local_product_name_ids': array - Lọc theo danh sách local_product_name_id
     *   - 'type': int - Lọc theo type
     * @return array Kết quả tổng hợp theo local_product_name_id
     */
    public function calculate_total_revenue($blog_id = null, $date = null, $options = array())
    {
        global $wpdb;

        if ($blog_id === null) {
            $blog_id = get_current_blog_id();
        }

        $table = $wpdb->prefix . 'product_roll_up';

        // Chuẩn bị WHERE clause
        $where_parts = array();
        $prepare_values = array();

        // Lọc theo blog_id
        $where_parts[] = "blog_id = %d";
        $prepare_values[] = $blog_id;

        // Lọc theo ngày
        if ($date !== null) {
            $where_parts[] = "roll_up_date = %s";
            $prepare_values[] = $date;
        } elseif (!empty($options['start_date']) && !empty($options['end_date'])) {
            // Lọc theo khoảng thời gian
            $where_parts[] = "roll_up_date BETWEEN %s AND %s";
            $prepare_values[] = $options['start_date'];
            $prepare_values[] = $options['end_date'];
        } elseif (!empty($options['start_date'])) {
            $where_parts[] = "roll_up_date >= %s";
            $prepare_values[] = $options['start_date'];
        } elseif (!empty($options['end_date'])) {
            $where_parts[] = "roll_up_date <= %s";
            $prepare_values[] = $options['end_date'];
        }

        // Lọc theo local_product_name_ids
        if (!empty($options['local_product_name_ids']) && is_array($options['local_product_name_ids'])) {
            $placeholders = implode(',', array_fill(0, count($options['local_product_name_ids']), '%d'));
            $where_parts[] = "local_product_name_id IN ({$placeholders})";
            $prepare_values = array_merge($prepare_values, $options['local_product_name_ids']);
        }

        // Lọc theo type
        if (isset($options['type'])) {
            $where_parts[] = "type = %d";
            $prepare_values[] = $options['type'];
        }

        $where_clause = implode(' AND ', $where_parts);

        // Build query
        $sql = "SELECT
                    local_product_name_id,
                    SUM(amount_after_tax) as total_amount,
                    SUM(quantity) as total_quantity,
                FROM {$table}
                WHERE {$where_clause}
                GROUP BY local_product_name_id
                ORDER BY total_amount DESC";

        // Prepare và execute
        if (!empty($prepare_values)) {
            $sql = $wpdb->prepare($sql, $prepare_values);
        }

        $results = $wpdb->get_results($sql, ARRAY_A);

        // Cast kiểu dữ liệu
        if (!empty($results)) {
            foreach ($results as &$row) {
                $row['local_product_name_id'] = intval($row['local_product_name_id']);
                $row['total_amount'] = floatval($row['total_amount']);
                $row['total_quantity'] = intval($row['total_quantity']);
                $row['record_count'] = intval($row['record_count']);
            }
        }

        return $results;
    }

    /**
     * Tính tổng doanh thu thực tế cho một shop trong khoảng thời gian
     * Doanh thu = Tổng bán hàng (type 10) - Tổng trả hàng (type 11)
     *
     * @param int $blog_id Blog ID (null = blog hiện tại)
     * @param string|null $start_date Ngày bắt đầu (Y-m-d)
     * @param string|null $end_date Ngày kết thúc (Y-m-d)
     * @param array $options Tùy chọn:
     *   - 'local_product_name_ids': array - Lọc theo sản phẩm
     *   - 'type': int - Lọc theo type (nếu set, sẽ bỏ qua logic type 10 - type 11)
     * @return float Tổng doanh thu thực tế
     */
    public function get_total_revenue_sum($blog_id = null, $start_date = null, $end_date = null, $options = array())
    {
        global $wpdb;

        if ($blog_id === null) {
            $blog_id = get_current_blog_id();
        }

        $table = $wpdb->prefix . 'product_roll_up';

        // Chuẩn bị WHERE clause cơ bản
        $where_parts = array();
        $prepare_values = array();

        $where_parts[] = "blog_id = %d";
        $prepare_values[] = $blog_id;

        // Lọc theo thời gian
        if ($start_date && $end_date) {
            $where_parts[] = "roll_up_date BETWEEN %s AND %s";
            $prepare_values[] = $start_date;
            $prepare_values[] = $end_date;
        } elseif ($start_date) {
            $where_parts[] = "roll_up_date >= %s";
            $prepare_values[] = $start_date;
        } elseif ($end_date) {
            $where_parts[] = "roll_up_date <= %s";
            $prepare_values[] = $end_date;
        }

        // Lọc theo local_product_name_ids
        if (!empty($options['local_product_name_ids']) && is_array($options['local_product_name_ids'])) {
            $placeholders = implode(',', array_fill(0, count($options['local_product_name_ids']), '%d'));
            $where_parts[] = "local_product_name_id IN ({$placeholders})";
            $prepare_values = array_merge($prepare_values, $options['local_product_name_ids']);
        }

        $where_clause = implode(' AND ', $where_parts);

        // Nếu user chỉ định type cụ thể, sử dụng logic cũ (SUM amount với type đó)
        if (isset($options['type'])) {
            $sql = "SELECT SUM(amount_after_tax) as total_revenue
                    FROM {$table}
                    WHERE {$where_clause} AND type = %d";
            $prepare_values[] = $options['type'];

            if (!empty($prepare_values)) {
                $sql = $wpdb->prepare($sql, $prepare_values);
            }

            $result = $wpdb->get_var($sql);
            return $result ? floatval($result) : 0.0;
        }

        $prepare_values = array_merge(
            [
                TGS_LEDGER_TYPE_SALES,
                TGS_LEDGER_TYPE_RETURN,
            ],
            $prepare_values
        );

        $sql = $wpdb->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN type = %d THEN amount_after_tax ELSE 0 END), 0) -
                COALESCE(SUM(CASE WHEN type = %d THEN amount_after_tax ELSE 0 END), 0) AS total_revenue
            FROM {$table}
            WHERE {$where_clause}",
            $prepare_values
        );

        $result = $wpdb->get_var($sql);

        return $result ? floatval($result) : 0.0;
    }
}
