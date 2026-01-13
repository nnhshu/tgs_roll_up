<?php
/**
 * Sync Manager Class
 * Đồng bộ dữ liệu roll_up lên các shop cha (parent shops)
 *
 * @package TGS_Sync_Roll_Up
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Sync_Manager
{
    /**
     * Database instance
     */
    private $database;

    /**
     * Roll-up calculator instance
     */
    private $calculator;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->database = new TGS_Sync_Roll_Up_Database();
        $this->calculator = new TGS_Roll_Up_Calculator();
    }

    /**
     * Đồng bộ dữ liệu roll_up từ shop hiện tại lên các shop cha
     *
     * @param int $source_blog_id Blog ID nguồn
     * @param string $date Ngày cần sync
     * @return array Kết quả sync
     */
    public function sync_to_parents($source_blog_id, $date)
    {
        global $wpdb;

        $results = array(
            'success' => array(),
            'failed' => array(),
            'skipped' => array(),
            'source_blog_id' => $source_blog_id,
            'date' => $date,
            'synced_at' => current_time('mysql'),
        );

        // Lấy cấu hình của shop hiện tại
        $config = $this->database->get_config($source_blog_id);

        if (!$config || empty($config->parent_blog_id)) {
            $results['message'] = 'No parent shop configured';
            return $results;
        }

        // parent_blog_id là single value
        $parent_blog_id = intval($config->parent_blog_id);

        // Lấy TẤT CẢ records roll_up của shop con cho ngày này
        $table = $wpdb->prefix . 'product_roll_up';
        $date_parts = explode('-', $date);
        $year = intval($date_parts[0]);
        $month = intval($date_parts[1]);
        $day = intval($date_parts[2]);

        $source_records = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE blog_id = %d
             AND roll_up_year = %d
             AND roll_up_month = %d
             AND roll_up_day = %d",
            $source_blog_id,
            $year,
            $month,
            $day
        ), ARRAY_A);

        if (empty($source_records)) {
            $results['message'] = 'No roll_up data found for source blog';
            return $results;
        }

        // Sync lên shop cha duy nhất
        $synced_count = 0;

        // Chuyển sang blog cha
        switch_to_blog($parent_blog_id);

        try {
            foreach ($source_records as $record) {
                // Tạo data để sync lên cha (giữ nguyên blog_id của shop con)
                $parent_data = array(
                    'blog_id' => $source_blog_id,  // Giữ nguyên blog_id của shop con
                    'roll_up_date' => $record['roll_up_date'],
                    'roll_up_day' => $record['roll_up_day'],
                    'roll_up_month' => $record['roll_up_month'],
                    'roll_up_year' => $record['roll_up_year'],
                    'local_product_name_id' => $record['local_product_name_id'],
                    'amount_after_tax' => $record['amount_after_tax'],
                    'tax' => $record['tax'],
                    'quantity' => $record['quantity'],
                    'type' => $record['type'],
                );

                // Sync meta (lot_ids) từ shop con
                if (!empty($record['meta'])) {
                    $meta_data = json_decode($record['meta'], true);
                    if (is_array($meta_data) && isset($meta_data['lot_ids']) && is_array($meta_data['lot_ids'])) {
                        $parent_data['lot_ids'] = $meta_data['lot_ids'];
                    }
                }

                // Ghi đè dữ liệu cũ (overwrite = true) thay vì cộng dồn
                // Lưu ý: lot_ids sẽ được merge trong save_roll_up()
                $roll_up_id = $this->calculator->save_roll_up($parent_data, true);

                if ($roll_up_id) {
                    $synced_count++;
                }
            }

            $results['success'][] = array(
                'parent_blog_id' => $parent_blog_id,
                'records_synced' => count($source_records),
            );

        } catch (Exception $e) {
            $results['failed'][] = array(
                'parent_blog_id' => $parent_blog_id,
                'error' => $e->getMessage(),
            );
        } finally {
            restore_current_blog();
        }

        // Log kết quả
        $results['total_synced'] = $synced_count;
        $this->log_sync_result($source_blog_id, $results);

        return $results;
    }


    /**
     * Tạo children_summary từ danh sách đã clean + source mới
     *
     * @param array $cleaned_children Children đã được clean (không có overlap)
     * @param int $source_blog_id Blog ID nguồn
     * @param object $source_roll_up Dữ liệu roll_up của nguồn
     * @param array $source_included_ids Danh sách blog_ids mà source bao gồm
     * @return array Children summary mới
     */
    private function get_children_summary_from_cleaned($cleaned_children, $source_blog_id, $source_roll_up, $source_included_ids)
    {
        // Bắt đầu với danh sách đã clean
        $children = $cleaned_children;

        // Thêm source mới
        $children[$source_blog_id] = array(
            'blog_id' => $source_blog_id,
            'revenue_total' => $source_roll_up->revenue_total,
            'revenue_strategic' => $source_roll_up->revenue_strategic_products,
            'revenue_normal' => $source_roll_up->revenue_normal_products,
            'count_sales_orders' => $source_roll_up->count_sales_orders,
            'count_new_customers' => $source_roll_up->count_new_customers,
            'inventory_total_quantity' => $source_roll_up->inventory_total_quantity,
            'inventory_total_value' => $source_roll_up->inventory_total_value,
            'included_blog_ids' => $source_included_ids,
            'synced_at' => current_time('mysql'),
        );

        return $children;
    }

    /**
     * Lấy danh sách tất cả blog_ids đã được bao gồm trong roll_up của một shop
     * Bao gồm: chính nó + tất cả con/cháu (từ children_summary)
     *
     * @param int $blog_id Blog ID
     * @param string $date Ngày
     * @return array Danh sách blog_ids
     */
    private function get_included_blog_ids($blog_id, $date)
    {
        global $wpdb;

        $original_blog = get_current_blog_id();

        if ($blog_id != $original_blog) {
            switch_to_blog($blog_id);
        }

        $meta_table = $wpdb->prefix . 'roll_up_meta';
        $roll_up_table = $wpdb->prefix . 'product_roll_up';

        $meta = $wpdb->get_row($wpdb->prepare(
            "SELECT rm.children_summary
             FROM {$meta_table} rm
             INNER JOIN {$roll_up_table} r ON rm.roll_up_id = r.roll_up_id
             WHERE r.blog_id = %d AND r.roll_up_date = %s",
            $blog_id,
            $date
        ));

        if ($blog_id != $original_blog) {
            restore_current_blog();
        }

        // Bắt đầu với chính blog_id này
        $included_ids = array($blog_id);

        if ($meta && !empty($meta->children_summary)) {
            $children = json_decode($meta->children_summary, true) ?? array();

            foreach ($children as $child) {
                // Nếu child có included_blog_ids, dùng nó
                if (isset($child['included_blog_ids']) && is_array($child['included_blog_ids'])) {
                    $included_ids = array_merge($included_ids, $child['included_blog_ids']);
                } else {
                    // Fallback: chỉ thêm blog_id của child
                    if (isset($child['blog_id'])) {
                        $included_ids[] = $child['blog_id'];
                    }
                }
            }
        }

        return array_unique(array_map('intval', $included_ids));
    }

    /**
     * Lấy meta hiện tại của shop
     */
    private function get_existing_meta($blog_id, $date)
    {
        global $wpdb;

        $meta_table = $wpdb->prefix . 'roll_up_meta';
        $roll_up_table = $wpdb->prefix . 'product_roll_up';

        $meta = $wpdb->get_row($wpdb->prepare(
            "SELECT rm.own_data, rm.children_summary
             FROM {$meta_table} rm
             INNER JOIN {$roll_up_table} r ON rm.roll_up_id = r.roll_up_id
             WHERE r.blog_id = %d AND r.roll_up_date = %s",
            $blog_id,
            $date
        ));

        $result = array(
            'own_data' => array(),
            'children_summary' => array(),
        );

        if ($meta) {
            if (!empty($meta->own_data)) {
                $result['own_data'] = json_decode($meta->own_data, true) ?? array();
            }
            if (!empty($meta->children_summary)) {
                $result['children_summary'] = json_decode($meta->children_summary, true) ?? array();
            }
        }

        return $result;
    }

    /**
     * Kiểm tra blog có tồn tại không
     *
     * @param int $blog_id Blog ID
     * @return bool
     */
    private function blog_exists($blog_id)
    {
        global $wpdb;

        if (!is_multisite()) {
            return $blog_id == 1;
        }

        $blog = $wpdb->get_var($wpdb->prepare(
            "SELECT blog_id FROM {$wpdb->blogs} WHERE blog_id = %d AND deleted = 0",
            $blog_id
        ));

        return !empty($blog);
    }

    /**
     * Merge dữ liệu từ các shop con
     *
     * @param int $parent_blog_id Blog ID cha
     * @param string $date Ngày
     * @param array $own_data Dữ liệu tự thân của shop cha
     * @param array $children_data Dữ liệu các shop con
     * @return array Dữ liệu roll_up đã merge
     */
    private function merge_roll_up_data($parent_blog_id, $date, $own_data, $children_data)
    {
        // Khởi tạo dữ liệu roll_up từ own_data (dữ liệu tự thân)
        $merged = array(
            'blog_id' => $parent_blog_id,
            'roll_up_date' => $date,
            'revenue_total' => floatval($own_data['revenue_total'] ?? 0),
            'revenue_strategic_products' => floatval($own_data['revenue_strategic_products'] ?? 0),
            'revenue_normal_products' => floatval($own_data['revenue_normal_products'] ?? 0),
            'count_purchase_orders' => intval($own_data['count_purchase_orders'] ?? 0),
            'count_sales_orders' => intval($own_data['count_sales_orders'] ?? 0),
            'count_return_orders' => intval($own_data['count_return_orders'] ?? 0),
            'count_damaged_orders' => intval($own_data['count_damaged_orders'] ?? 0),
            'count_internal_import' => intval($own_data['count_internal_import'] ?? 0),
            'count_internal_export' => intval($own_data['count_internal_export'] ?? 0),
            'count_receipt_orders' => intval($own_data['count_receipt_orders'] ?? 0),
            'count_payment_orders' => intval($own_data['count_payment_orders'] ?? 0),
            'total_receipt_amount' => floatval($own_data['total_receipt_amount'] ?? 0),
            'total_payment_amount' => floatval($own_data['total_payment_amount'] ?? 0),
            'inventory_total_quantity' => floatval($own_data['inventory_total_quantity'] ?? 0),
            'inventory_total_value' => floatval($own_data['inventory_total_value'] ?? 0),
            'inventory_strategic_quantity' => floatval($own_data['inventory_strategic_quantity'] ?? 0),
            'inventory_normal_quantity' => floatval($own_data['inventory_normal_quantity'] ?? 0),
            'inventory_expiry_summary' => null,
            'count_new_customers' => intval($own_data['count_new_customers'] ?? 0),
            'count_returning_customers' => intval($own_data['count_returning_customers'] ?? 0),
            'count_total_customers' => intval($own_data['count_total_customers'] ?? 0),
            'avg_order_value' => 0,
            'top_selling_products' => null,
            'slow_moving_products' => null,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        );

        // Cộng dồn từ các shop con
        foreach ($children_data as $child) {
            $merged['revenue_total'] += floatval($child['revenue_total'] ?? 0);
            $merged['revenue_strategic_products'] += floatval($child['revenue_strategic'] ?? $child['revenue_strategic_products'] ?? 0);
            $merged['revenue_normal_products'] += floatval($child['revenue_normal'] ?? $child['revenue_normal_products'] ?? 0);
            $merged['count_sales_orders'] += intval($child['count_sales_orders'] ?? 0);
            $merged['count_new_customers'] += intval($child['count_new_customers'] ?? 0);
            $merged['inventory_total_quantity'] += floatval($child['inventory_total_quantity'] ?? 0);
            $merged['inventory_total_value'] += floatval($child['inventory_total_value'] ?? 0);
        }

        // Tính giá trị đơn hàng trung bình
        if ($merged['count_sales_orders'] > 0) {
            $merged['avg_order_value'] = $merged['revenue_total'] / $merged['count_sales_orders'];
        }

        return $merged;
    }

    /**
     * Lưu metadata cho shop cha
     *
     * @param int $roll_up_id Roll_up ID
     * @param int $parent_blog_id Blog ID cha
     * @param string $date Ngày
     * @param array $own_data Dữ liệu tự thân
     * @param array $children_data Dữ liệu các shop con
     */
    private function save_parent_meta($roll_up_id, $parent_blog_id, $date, $own_data, $children_data)
    {
        $meta_data = array(
            'own_data' => $own_data,
            'children_summary' => $children_data,
            'sync_log' => array(
                'last_sync' => current_time('mysql'),
                'children_count' => count($children_data),
                'children_ids' => array_keys($children_data),
            ),
        );

        $this->calculator->save_roll_up_meta($roll_up_id, $parent_blog_id, $date, $meta_data);
    }

    /**
     * Cập nhật thời gian sync cuối cùng
     *
     * @param int $blog_id Blog ID
     * @param string $date Ngày
     */
    private function update_last_sync_time($blog_id, $date)
    {
        global $wpdb;

        $table = $wpdb->prefix . 'product_roll_up';

        $wpdb->update(
            $table,
            array('last_sync_at' => current_time('mysql')),
            array(
                'blog_id' => $blog_id,
                'roll_up_date' => $date,
            ),
            array('%s'),
            array('%d', '%s')
        );
    }

    /**
     * Log kết quả sync
     *
     * @param int $blog_id Blog ID
     * @param array $results Kết quả sync
     */
    private function log_sync_result($blog_id, $results)
    {
        $log_option = 'tgs_sync_log_' . $blog_id;
        $existing_logs = get_option($log_option, array());

        // Giữ tối đa 100 log gần nhất
        if (count($existing_logs) >= 100) {
            array_shift($existing_logs);
        }

        $existing_logs[] = array(
            'timestamp' => current_time('mysql'),
            'date' => $results['date'],
            'success_count' => count($results['success']),
            'failed_count' => count($results['failed']),
            'details' => $results,
        );

        update_option($log_option, $existing_logs);
    }

    /**
     * Sync toàn bộ dữ liệu từ shop con lên các shop cha
     * Dùng cho việc rebuild hoặc sync lần đầu
     *
     * @param int $source_blog_id Blog ID nguồn
     * @param string $start_date Ngày bắt đầu
     * @param string $end_date Ngày kết thúc
     * @return array Kết quả sync
     */
    public function full_sync($source_blog_id, $start_date, $end_date)
    {
        $results = array(
            'total_days' => 0,
            'success_days' => 0,
            'failed_days' => 0,
            'details' => array(),
        );

        $current = strtotime($start_date);
        $end = strtotime($end_date);

        while ($current <= $end) {
            $date = date('Y-m-d', $current);
            $results['total_days']++;

            $sync_result = $this->sync_to_parents($source_blog_id, $date);

            if (empty($sync_result['failed'])) {
                $results['success_days']++;
            } else {
                $results['failed_days']++;
            }

            $results['details'][$date] = $sync_result;

            $current = strtotime('+1 day', $current);
        }

        return $results;
    }

    /**
     * Lấy danh sách các shop con của một shop cha
     *
     * @param int $parent_blog_id Blog ID cha
     * @return array Danh sách blog IDs
     */
    public function get_children_blogs($parent_blog_id)
    {
        global $wpdb;

        $blogs = $this->database->get_all_blogs();
        $children = array();

        foreach ($blogs as $blog) {
            // Chuyển sang blog đó để đọc config
            switch_to_blog($blog->blog_id);

            $config_table = $wpdb->prefix . 'sync_roll_up_config';

            // Kiểm tra bảng config có tồn tại không
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$config_table'");

            if ($table_exists) {
                $config = $wpdb->get_row(
                    "SELECT parent_blog_ids FROM $config_table WHERE blog_id = " . intval($blog->blog_id)
                );

                if ($config && !empty($config->parent_blog_ids)) {
                    $parent_ids = json_decode($config->parent_blog_ids, true);
                    if (is_array($parent_ids) && in_array($parent_blog_id, $parent_ids)) {
                        $children[] = $blog->blog_id;
                    }
                }
            }

            restore_current_blog();
        }

        return $children;
    }

    /**
     * Kiểm tra trạng thái sync của các shop con
     *
     * @param int $parent_blog_id Blog ID cha
     * @param string $date Ngày
     * @return array Trạng thái sync
     */
    public function get_sync_status($parent_blog_id, $date)
    {
        switch_to_blog($parent_blog_id);

        global $wpdb;

        $meta_table = $wpdb->prefix . 'roll_up_meta';
        $roll_up_table = $wpdb->prefix . 'product_roll_up';

        $meta = $wpdb->get_row($wpdb->prepare(
            "SELECT rm.children_summary, rm.sync_log, r.last_sync_at
             FROM $meta_table rm
             INNER JOIN $roll_up_table r ON rm.roll_up_id = r.roll_up_id
             WHERE r.blog_id = %d AND r.roll_up_date = %s",
            $parent_blog_id,
            $date
        ));

        restore_current_blog();

        if (!$meta) {
            return array(
                'has_data' => false,
                'children_count' => 0,
                'last_sync' => null,
            );
        }

        $children = json_decode($meta->children_summary, true) ?? array();
        $sync_log = json_decode($meta->sync_log, true) ?? array();

        return array(
            'has_data' => true,
            'children_count' => count($children),
            'children_ids' => array_keys($children),
            'last_sync' => $meta->last_sync_at,
            'sync_log' => $sync_log,
        );
    }

    /**
     * Trigger sync từ AJAX hoặc cron
     *
     * @param int $blog_id Blog ID
     * @return array Kết quả sync
     */
    public function trigger_sync($blog_id = null)
    {
        if (!$blog_id) {
            $blog_id = get_current_blog_id();
        }

        $today = current_time('Y-m-d');

        // Tính roll_up cho ngày hôm nay (trả về array các records)
        $roll_up_data = $this->calculator->calculate_daily_roll_up($blog_id, $today);

        // Lưu từng record vào DB
        $saved_ids = array();
        foreach ($roll_up_data as $data) {
            $roll_up_id = $this->calculator->save_roll_up($data);
            if ($roll_up_id) {
                $saved_ids[] = $roll_up_id;
            }
        }

        // Sync lên các shop cha
        $sync_result = $this->sync_to_parents($blog_id, $today);

        return array(
            'blog_id' => $blog_id,
            'date' => $today,
            'saved_count' => count($saved_ids),
            'saved_ids' => $saved_ids,
            'sync_result' => $sync_result,
        );
    }

    /**
     * Lấy log sync gần đây
     *
     * @param int $blog_id Blog ID
     * @param int $limit Số lượng log
     * @return array Danh sách log
     */
    public function get_recent_sync_logs($blog_id, $limit = 10)
    {
        $log_option = 'tgs_sync_log_' . $blog_id;
        $logs = get_option($log_option, array());

        // Lấy các log gần nhất
        $logs = array_slice($logs, -$limit);

        return array_reverse($logs);
    }

    /**
     * Xóa log sync cũ
     *
     * @param int $blog_id Blog ID
     * @param int $days_to_keep Số ngày giữ lại
     */
    public function cleanup_old_logs($blog_id, $days_to_keep = 30)
    {
        $log_option = 'tgs_sync_log_' . $blog_id;
        $logs = get_option($log_option, array());

        $cutoff = strtotime("-{$days_to_keep} days");

        $filtered_logs = array_filter($logs, function ($log) use ($cutoff) {
            return strtotime($log['timestamp']) >= $cutoff;
        });

        update_option($log_option, array_values($filtered_logs));
    }
}
