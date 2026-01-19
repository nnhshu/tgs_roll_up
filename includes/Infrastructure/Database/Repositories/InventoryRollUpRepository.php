<?php
/**
 * Inventory Roll-Up Repository
 * Repository cho inventory_roll_up table
 *
 * @package TGS_Sync_Roll_Up
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class InventoryRollUpRepository
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
     * Save inventory record
     *
     * @param array $data Inventory data
     * @param bool $overwrite Whether to overwrite or add to existing
     * @return int Record ID
     */
    public function save(array $data, bool $overwrite = false): int
    {
        $table = $this->wpdb->prefix . 'inventory_roll_up';

        // Prepare data
        $insert_data = [
            'blog_id' => $data['blog_id'] ?? get_current_blog_id(),
            'local_product_name_id' => $data['local_product_name_id'] ?? 0,
            'global_product_name_id' => $data['global_product_name_id'] ?? null,
            'roll_up_date' => $data['roll_up_date'] ?? current_time('Y-m-d'),
            'in_qty' => $data['in_qty'] ?? 0,
            'in_value' => $data['in_value'] ?? 0,
            'out_qty' => $data['out_qty'] ?? 0,
            'out_value' => $data['out_value'] ?? 0,
            'end_qty' => $data['end_qty'] ?? 0,
            'end_value' => $data['end_value'] ?? 0,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ];

        // Parse roll_up_date để lấy day, month, year
        $date_parts = explode('-', $insert_data['roll_up_date']);
        $insert_data['roll_up_year'] = intval($date_parts[0]);
        $insert_data['roll_up_month'] = intval($date_parts[1]);
        $insert_data['roll_up_day'] = $data['roll_up_day'] ?? intval($date_parts[2]);

        // Meta
        if (!empty($data['meta'])) {
            $insert_data['meta'] = is_string($data['meta']) ? $data['meta'] : json_encode($data['meta']);
        }

        // ON DUPLICATE KEY UPDATE
        $on_duplicate = $overwrite
            ? "in_qty = VALUES(in_qty),
               in_value = VALUES(in_value),
               out_qty = VALUES(out_qty),
               out_value = VALUES(out_value),
               end_qty = VALUES(end_qty),
               end_value = VALUES(end_value),
               meta = VALUES(meta),
               updated_at = VALUES(updated_at)"
            : "in_qty = in_qty + VALUES(in_qty),
               in_value = in_value + VALUES(in_value),
               out_qty = out_qty + VALUES(out_qty),
               out_value = out_value + VALUES(out_value),
               end_qty = end_qty + VALUES(end_qty),
               end_value = end_value + VALUES(end_value),
               updated_at = VALUES(updated_at)";

        // Build query
        $fields = array_keys($insert_data);
        $placeholders = array_fill(0, count($fields), '%s');

        $query = "INSERT INTO {$table} (" . implode(', ', $fields) . ")
                  VALUES (" . implode(', ', $placeholders) . ")
                  ON DUPLICATE KEY UPDATE {$on_duplicate}";

        $result = $this->wpdb->query(
            $this->wpdb->prepare($query, ...array_values($insert_data))
        );

        if ($result === false) {
            return 0;
        }

        return $this->wpdb->insert_id ?: 1;
    }

    /**
     * Find inventory records by blog and date
     *
     * @param int $blogId Blog ID
     * @param string $date Date (Y-m-d)
     * @return array|null Records
     */
    public function findByBlogAndDate(int $blogId, string $date): ?array
    {
        $table = $this->wpdb->prefix . 'inventory_roll_up';
        $date_parts = explode('-', $date);
        $year = intval($date_parts[0]);
        $month = intval($date_parts[1]);
        $day = intval($date_parts[2]);

        $results = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE blog_id = %d
             AND roll_up_year = %d
             AND roll_up_month = %d
             AND roll_up_day = %d",
            $blogId,
            $year,
            $month,
            $day
        ), ARRAY_A);

        return $results ?: null;
    }

    /**
     * Find monthly total inventory (roll_up_day = 0)
     *
     * @param int $blogId Blog ID
     * @param int $year Year
     * @param int $month Month
     * @return array|null Records
     */
    public function findMonthlyTotal(int $blogId, int $year, int $month): ?array
    {
        $table = $this->wpdb->prefix . 'inventory_roll_up';

        $results = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE blog_id = %d
             AND roll_up_year = %d
             AND roll_up_month = %d
             AND roll_up_day = 0",
            $blogId,
            $year,
            $month
        ), ARRAY_A);

        return $results ?: null;
    }

    /**
     * Find inventory records by date range
     *
     * @param int $blogId Blog ID
     * @param string $fromDate From date
     * @param string $toDate To date
     * @return array Records
     */
    public function findByDateRange(int $blogId, string $fromDate, string $toDate): array
    {
        $table = $this->wpdb->prefix . 'inventory_roll_up';

        return $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE blog_id = %d
             AND roll_up_date BETWEEN %s AND %s
             ORDER BY roll_up_date ASC",
            $blogId,
            $fromDate,
            $toDate
        ), ARRAY_A) ?: [];
    }

    /**
     * Delete inventory records by date range
     *
     * @param int $blogId Blog ID
     * @param string $fromDate From date
     * @param string $toDate To date
     * @return bool Success
     */
    public function deleteByDateRange(int $blogId, string $fromDate, string $toDate): bool
    {
        $table = $this->wpdb->prefix . 'inventory_roll_up';

        $result = $this->wpdb->query($this->wpdb->prepare(
            "DELETE FROM {$table}
             WHERE blog_id = %d
             AND roll_up_date BETWEEN %s AND %s",
            $blogId,
            $fromDate,
            $toDate
        ));

        return $result !== false;
    }

    /**
     * Get current inventory for products
     *
     * @param int $blogId Blog ID
     * @param array $productIds Product IDs (empty for all)
     * @return array Inventory data
     */
    public function getCurrentInventory(int $blogId, array $productIds = []): array
    {
        $table = $this->wpdb->prefix . 'inventory_roll_up';

        // Get latest month's total (roll_up_day = 0)
        $query = "SELECT * FROM {$table}
                  WHERE blog_id = %d
                  AND roll_up_day = 0";

        $params = [$blogId];

        if (!empty($productIds)) {
            $placeholders = implode(',', array_fill(0, count($productIds), '%d'));
            $query .= " AND local_product_name_id IN ({$placeholders})";
            $params = array_merge($params, $productIds);
        }

        $query .= " ORDER BY roll_up_year DESC, roll_up_month DESC";

        return $this->wpdb->get_results(
            $this->wpdb->prepare($query, ...$params),
            ARRAY_A
        ) ?: [];
    }
}
