<?php
/**
 * Cron Handler Class
 * Xử lý các tác vụ cron cho việc sync roll_up
 *
 * @package TGS_Sync_Roll_Up
 * @since 1.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class TGS_Cron_Handler
{
    /**
     * Sync manager instance
     */
    private $sync_manager;

    /**
     * Calculator instance
     */
    private $calculator;

    /**
     * Database instance
     */
    private $database;

    /**
     * Cron hook name
     */
    const CRON_HOOK = 'tgs_sync_roll_up_cron';

    /**
     * Daily cleanup hook
     */
    const CLEANUP_HOOK = 'tgs_sync_roll_up_cleanup';

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->sync_manager = new TGS_Sync_Manager();
        $this->calculator = new TGS_Roll_Up_Calculator();
        $this->database = new TGS_Sync_Roll_Up_Database();

        // Đăng ký các hooks (không cần monthly hook vì sử dụng GROUP BY thay vì bảng riêng)
        add_action(self::CRON_HOOK, array($this, 'run_sync_cron'));
        add_action(self::CLEANUP_HOOK, array($this, 'run_cleanup_cron'));

        // Thêm custom cron schedule
        add_filter('cron_schedules', array($this, 'add_cron_schedules'));
    }

    /**
     * Thêm custom cron schedules
     *
     * @param array $schedules Existing schedules
     * @return array Modified schedules
     */
    public function add_cron_schedules($schedules)
    {
        // Mỗi 15 phút
        $schedules['every_fifteen_minutes'] = array(
            'interval' => 15 * MINUTE_IN_SECONDS,
            'display' => __('Every 15 Minutes', 'tgs-sync-roll-up'),
        );

        // Mỗi 30 phút
        $schedules['every_thirty_minutes'] = array(
            'interval' => 30 * MINUTE_IN_SECONDS,
            'display' => __('Every 30 Minutes', 'tgs-sync-roll-up'),
        );

        // Mỗi 2 giờ
        $schedules['every_two_hours'] = array(
            'interval' => 2 * HOUR_IN_SECONDS,
            'display' => __('Every 2 Hours', 'tgs-sync-roll-up'),
        );

        // Mỗi 4 giờ
        $schedules['every_four_hours'] = array(
            'interval' => 4 * HOUR_IN_SECONDS,
            'display' => __('Every 4 Hours', 'tgs-sync-roll-up'),
        );

        // Mỗi 6 giờ
        $schedules['every_six_hours'] = array(
            'interval' => 6 * HOUR_IN_SECONDS,
            'display' => __('Every 6 Hours', 'tgs-sync-roll-up'),
        );

        return $schedules;
    }

    /**
     * Lên lịch các cron jobs
     *
     * @param string $frequency Tần suất (hourly, every_two_hours, etc.)
     */
    public function schedule_crons($frequency = 'hourly')
    {
        // Schedule main sync cron
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), $frequency, self::CRON_HOOK);
        }

        // Schedule daily cleanup
        if (!wp_next_scheduled(self::CLEANUP_HOOK)) {
            wp_schedule_event(strtotime('tomorrow 03:00'), 'daily', self::CLEANUP_HOOK);
        }

        // Không cần monthly aggregation schedule - sử dụng GROUP BY trên roll_up_day/month/year
    }

    /**
     * Hủy tất cả cron jobs
     */
    public function unschedule_crons()
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
        wp_clear_scheduled_hook(self::CLEANUP_HOOK);
    }

    /**
     * Cập nhật tần suất cron
     *
     * @param string $new_frequency Tần suất mới
     */
    public function update_cron_frequency($new_frequency)
    {
        // Hủy cron hiện tại
        wp_clear_scheduled_hook(self::CRON_HOOK);

        // Lên lịch lại với tần suất mới
        wp_schedule_event(time(), $new_frequency, self::CRON_HOOK);
    }

    /**
     * Chạy sync cron job
     * Được gọi bởi WP Cron
     */
    public function run_sync_cron()
    {
        // Log start
        $this->log_cron_execution('start', 'sync');

        $blog_id = get_current_blog_id();

        try {
            // Kiểm tra config xem có enabled không
            $config = $this->database->get_config($blog_id);

            if (!$config || !$config->sync_enabled) {
                $this->log_cron_execution('skipped', 'sync', 'Sync not enabled');
                return;
            }

            // Chạy sync
            $result = $this->sync_manager->trigger_sync($blog_id);

            // Log success
            $this->log_cron_execution('success', 'sync', $result);

        } catch (Exception $e) {
            // Log error
            $this->log_cron_execution('error', 'sync', $e->getMessage());
        }
    }

    /**
     * Chạy cleanup cron job
     * Dọn dẹp log cũ và dữ liệu tạm
     */
    public function run_cleanup_cron()
    {
        $this->log_cron_execution('start', 'cleanup');

        $blog_id = get_current_blog_id();

        try {
            // Dọn dẹp log sync cũ (giữ 30 ngày)
            $this->sync_manager->cleanup_old_logs($blog_id, 30);

            // Dọn dẹp transients
            $this->cleanup_transients();

            $this->log_cron_execution('success', 'cleanup');

        } catch (Exception $e) {
            $this->log_cron_execution('error', 'cleanup', $e->getMessage());
        }
    }

    /**
     * Lấy monthly summary từ bảng roll_up sử dụng GROUP BY
     * Không cần cron riêng, query trực tiếp khi cần
     *
     * @param int $blog_id Blog ID
     * @param string $month Tháng (Y-m-01)
     * @return object|null Monthly summary
     */
    public function get_monthly_summary($blog_id, $month)
    {
        return $this->calculator->get_monthly_summary($blog_id, $month);
    }

    /**
     * Sync roll_up cho tất cả các blogs trong mạng
     * Dùng cho network admin
     */
    public function run_network_sync()
    {
        if (!is_multisite()) {
            return $this->sync_manager->trigger_sync();
        }

        $results = array(
            'total' => 0,
            'success' => 0,
            'failed' => 0,
            'details' => array(),
        );

        $blogs = $this->database->get_all_blogs();

        foreach ($blogs as $blog) {
            $results['total']++;

            switch_to_blog($blog->blog_id);

            try {
                $config = $this->database->get_config($blog->blog_id);

                if ($config && $config->sync_enabled) {
                    $sync_result = $this->sync_manager->trigger_sync($blog->blog_id);
                    $results['success']++;
                    $results['details'][$blog->blog_id] = array(
                        'status' => 'success',
                        'result' => $sync_result,
                    );
                } else {
                    $results['details'][$blog->blog_id] = array(
                        'status' => 'skipped',
                        'reason' => 'Sync not enabled',
                    );
                }
            } catch (Exception $e) {
                $results['failed']++;
                $results['details'][$blog->blog_id] = array(
                    'status' => 'error',
                    'error' => $e->getMessage(),
                );
            }

            restore_current_blog();
        }

        return $results;
    }

    /**
     * Log cron execution
     *
     * @param string $status Trạng thái (start, success, error, skipped)
     * @param string $type Loại cron (sync, cleanup, monthly)
     * @param mixed $data Dữ liệu bổ sung
     */
    private function log_cron_execution($status, $type, $data = null)
    {
        $log_option = 'tgs_cron_log_' . get_current_blog_id();
        $logs = get_option($log_option, array());

        // Giới hạn 50 log
        if (count($logs) >= 50) {
            array_shift($logs);
        }

        $logs[] = array(
            'timestamp' => current_time('mysql'),
            'type' => $type,
            'status' => $status,
            'data' => $data,
        );

        update_option($log_option, $logs);

        // Cũng log vào error_log nếu có lỗi
        if ($status === 'error') {
            error_log(sprintf(
                '[TGS Sync Roll Up] Cron %s error: %s',
                $type,
                is_string($data) ? $data : json_encode($data)
            ));
        }
    }

    /**
     * Dọn dẹp transients
     */
    private function cleanup_transients()
    {
        global $wpdb;

        // Xóa các transients đã hết hạn
        $wpdb->query("
            DELETE FROM {$wpdb->options}
            WHERE option_name LIKE '_transient_tgs_sync_%'
            OR option_name LIKE '_transient_timeout_tgs_sync_%'
        ");
    }

    /**
     * Lấy thông tin cron tiếp theo
     *
     * @return array Thông tin các cron
     */
    public function get_next_scheduled()
    {
        return array(
            'sync' => array(
                'next_run' => wp_next_scheduled(self::CRON_HOOK),
                'next_run_formatted' => wp_next_scheduled(self::CRON_HOOK)
                    ? date_i18n('Y-m-d H:i:s', wp_next_scheduled(self::CRON_HOOK))
                    : null,
            ),
            'cleanup' => array(
                'next_run' => wp_next_scheduled(self::CLEANUP_HOOK),
                'next_run_formatted' => wp_next_scheduled(self::CLEANUP_HOOK)
                    ? date_i18n('Y-m-d H:i:s', wp_next_scheduled(self::CLEANUP_HOOK))
                    : null,
            ),
            // Không cần monthly schedule - sử dụng GROUP BY thay vì bảng riêng
        );
    }

    /**
     * Lấy log cron gần đây
     *
     * @param int $limit Số lượng log
     * @return array Danh sách log
     */
    public function get_recent_cron_logs($limit = 20)
    {
        $log_option = 'tgs_cron_log_' . get_current_blog_id();
        $logs = get_option($log_option, array());

        return array_slice(array_reverse($logs), 0, $limit);
    }

    /**
     * Chạy cron thủ công
     *
     * @param string $type Loại cron (sync, cleanup)
     * @return mixed Kết quả
     */
    public function run_manual($type)
    {
        switch ($type) {
            case 'sync':
                return $this->run_sync_cron();
            case 'cleanup':
                return $this->run_cleanup_cron();
            // Không cần monthly - sử dụng get_monthly_summary() thay thế
            default:
                return new WP_Error('invalid_type', 'Invalid cron type');
        }
    }

    /**
     * Kiểm tra xem cron có đang chạy không
     *
     * @return bool
     */
    public function is_cron_running()
    {
        return get_transient('tgs_sync_cron_running_' . get_current_blog_id()) === 'yes';
    }

    /**
     * Đánh dấu cron đang chạy
     */
    public function mark_cron_running()
    {
        set_transient('tgs_sync_cron_running_' . get_current_blog_id(), 'yes', 5 * MINUTE_IN_SECONDS);
    }

    /**
     * Đánh dấu cron đã hoàn thành
     */
    public function mark_cron_complete()
    {
        delete_transient('tgs_sync_cron_running_' . get_current_blog_id());
    }

    /**
     * Sync một ngày cụ thể cho một blog
     *
     * @param int $blog_id Blog ID
     * @param string $date Ngày (Y-m-d)
     * @return array Kết quả
     */
    public function sync_specific_date($blog_id, $date)
    {
        $original_blog = get_current_blog_id();

        if ($blog_id != $original_blog) {
            switch_to_blog($blog_id);
        }

        try {
            // Tính roll_up cho ngày đó (dữ liệu tự thân của shop)
            $own_roll_up = $this->calculator->calculate_daily_roll_up($blog_id, $date);
            error_log('Own Roll Up: ' . print_r($own_roll_up, true));
            // Lưu roll_up vào DB của chính shop này
            // calculate_daily_roll_up trả về array các records theo local_product_name_id
            $saved_ids = array();
            foreach ($own_roll_up as $data) {
                $roll_up_id = $this->calculator->save_roll_up($data);
                if ($roll_up_id) {
                    $saved_ids[] = $roll_up_id;
                }
            }

            // Sync lên các shop cha (chỉ gọi 1 lần sau khi đã lưu hết)
            $sync_result = $this->sync_manager->sync_to_parents($blog_id, $date);

            $result = array(
                'success' => true,
                'blog_id' => $blog_id,
                'date' => $date,
                'saved_count' => count($saved_ids),
                'sync_result' => $sync_result,
            );
        } catch (Exception $e) {
            $result = array(
                'success' => false,
                'blog_id' => $blog_id,
                'date' => $date,
                'error' => $e->getMessage(),
            );
        }

        if ($blog_id != $original_blog) {
            restore_current_blog();
        }

        return $result;
    }

    /**
     * Lấy meta hiện tại của shop
     */
    private function get_existing_meta($blog_id, $date)
    {
        global $wpdb;

        $meta_table = $wpdb->prefix . 'roll_up_meta';
        $roll_up_table = $wpdb->prefix . 'roll_up';

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
     * Trích xuất own_data từ roll_up
     */
    private function extract_own_data($roll_up)
    {
        return array(
            'revenue_total' => $roll_up['revenue_total'] ?? 0,
            'revenue_strategic_products' => $roll_up['revenue_strategic_products'] ?? 0,
            'revenue_normal_products' => $roll_up['revenue_normal_products'] ?? 0,
            'count_purchase_orders' => $roll_up['count_purchase_orders'] ?? 0,
            'count_sales_orders' => $roll_up['count_sales_orders'] ?? 0,
            'count_return_orders' => $roll_up['count_return_orders'] ?? 0,
            'count_new_customers' => $roll_up['count_new_customers'] ?? 0,
            'count_returning_customers' => $roll_up['count_returning_customers'] ?? 0,
            'inventory_total_quantity' => $roll_up['inventory_total_quantity'] ?? 0,
            'inventory_total_value' => $roll_up['inventory_total_value'] ?? 0,
        );
    }

    /**
     * Merge roll_up tự thân với children_summary
     */
    private function merge_with_children($own_roll_up, $children_summary)
    {
        if (empty($children_summary)) {
            return $own_roll_up;
        }

        $merged = $own_roll_up;
        
        foreach ($children_summary as $child) {
            $merged['revenue_total'] += floatval($child['revenue_total'] ?? 0);
            $merged['revenue_strategic_products'] += floatval($child['revenue_strategic'] ?? $child['revenue_strategic_products'] ?? 0);
            $merged['revenue_normal_products'] += floatval($child['revenue_normal'] ?? $child['revenue_normal_products'] ?? 0);
            $merged['count_sales_orders'] += intval($child['count_sales_orders'] ?? 0);
            $merged['count_new_customers'] += intval($child['count_new_customers'] ?? 0);
            $merged['inventory_total_quantity'] += floatval($child['inventory_total_quantity'] ?? 0);
            $merged['inventory_total_value'] += floatval($child['inventory_total_value'] ?? 0);
        }

        // Tính lại avg_order_value
        if ($merged['count_sales_orders'] > 0) {
            $merged['avg_order_value'] = $merged['revenue_total'] / $merged['count_sales_orders'];
        }

        return $merged;
    }

    /**
     * Rebuild roll_up cho một khoảng thời gian
     *
     * @param int $blog_id Blog ID
     * @param string $start_date Ngày bắt đầu
     * @param string $end_date Ngày kết thúc
     * @param bool $sync_to_parents Có sync lên shop cha không
     * @return array Kết quả rebuild
     */
    public function rebuild_date_range($blog_id, $start_date, $end_date, $sync_to_parents = true)
    {
        $results = array(
            'total' => 0,
            'success' => 0,
            'failed' => 0,
            'dates' => array(),
        );

        $original_blog = get_current_blog_id();

        if ($blog_id != $original_blog) {
            switch_to_blog($blog_id);
        }

        try {
            $current = strtotime($start_date);
            $end = strtotime($end_date);

            while ($current <= $end) {
                $date = date('Y-m-d', $current);
                $results['total']++;

                try {
                    // Sử dụng sync_specific_date để tính và lưu roll_up cho ngày này
                    $sync_result = $this->sync_specific_date($blog_id, $date);

                    // Sync lên shop cha nếu được yêu cầu
                    $parent_sync_result = null;
                    if ($sync_to_parents) {
                        $parent_sync_result = $this->sync_manager->sync_to_parents($blog_id, $date);
                    }

                    $results['success']++;
                    $results['dates'][$date] = array(
                        'status' => 'success',
                        'records_saved' => $sync_result['records_saved'] ?? 0,
                        'parent_sync_result' => $parent_sync_result,
                    );

                } catch (Exception $e) {
                    $results['failed']++;
                    $results['dates'][$date] = array(
                        'status' => 'failed',
                        'error' => $e->getMessage(),
                    );
                }

                $current = strtotime('+1 day', $current);
            }

        } finally {
            if ($blog_id != $original_blog) {
                restore_current_blog();
            }
        }

        return $results;
    }
}
