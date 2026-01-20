<?php
/**
 * Order Roll-Up Repository
 * Repository cho order_roll_up table
 *
 * @package TGS_Sync_Roll_Up
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class OrderRollUpRepository
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
     * Save order record
     *
     * @param array $data Order data
     * @param bool $overwrite Whether to overwrite or add to existing
     * @return int Record ID
     */
    public function save(array $data, bool $overwrite = false): int
    {
        $table = $this->wpdb->prefix . 'order_roll_up';

        // Prepare data
        $insert_data = [
            'blog_id' => $data['blog_id'] ?? get_current_blog_id(),
            'roll_up_date' => $data['roll_up_date'] ?? current_time('Y-m-d'),
            'count' => $data['count'] ?? 0,
            'value' => $data['value'] ?? 0,
            'source' => $data['source'] ?? 0,
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
            ? "count = VALUES(count),
               value = VALUES(value),
               meta = VALUES(meta),
               source = VALUES(source),
               updated_at = VALUES(updated_at)"
            : "count = count + VALUES(count),
               value = value + VALUES(value),
               meta = JSON_MERGE_PRESERVE(COALESCE(meta, '{}'), VALUES(meta)),
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
     * Find order records by blog and date
     *
     * @param int $blogId Blog ID
     * @param string $date Date (Y-m-d)
     * @return array|null Records
     */
    public function findByBlogAndDate(int $blogId, string $date): ?array
    {
        $table = $this->wpdb->prefix . 'order_roll_up';
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
     * Find monthly total orders (roll_up_day = 0)
     *
     * @param int $blogId Blog ID
     * @param int $year Year
     * @param int $month Month
     * @return array|null Record
     */
    public function findMonthlyTotal(int $blogId, int $year, int $month): ?array
    {
        $table = $this->wpdb->prefix . 'order_roll_up';

        $result = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$table}
             WHERE blog_id = %d
             AND roll_up_year = %d
             AND roll_up_month = %d
             AND roll_up_day = 0",
            $blogId,
            $year,
            $month
        ), ARRAY_A);

        return $result ?: null;
    }

    /**
     * Find order records by date range
     *
     * @param int $blogId Blog ID
     * @param string $fromDate From date
     * @param string $toDate To date
     * @return array Records
     */
    public function findByDateRange(int $blogId, string $fromDate, string $toDate): array
    {
        $table = $this->wpdb->prefix . 'order_roll_up';

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
     * Delete order records by date range
     *
     * @param int $blogId Blog ID
     * @param string $fromDate From date
     * @param string $toDate To date
     * @return bool Success
     */
    public function deleteByDateRange(int $blogId, string $fromDate, string $toDate): bool
    {
        $table = $this->wpdb->prefix . 'order_roll_up';

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
     * Get order statistics by date range
     *
     * @param int $blogId Blog ID
     * @param string $fromDate From date
     * @param string $toDate To date
     * @return array Statistics
     */
    public function getStatsByDateRange(int $blogId, string $fromDate, string $toDate): array
    {
        $table = $this->wpdb->prefix . 'order_roll_up';

        $result = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT
                SUM(count) as total_orders,
                SUM(value) as total_value,
                AVG(value) as avg_order_value
             FROM {$table}
             WHERE blog_id = %d
             AND roll_up_date BETWEEN %s AND %s
             AND roll_up_day > 0",
            $blogId,
            $fromDate,
            $toDate
        ), ARRAY_A);

        if (!$result) {
            return [
                'total_orders' => 0,
                'total_value' => 0,
                'avg_order_value' => 0,
            ];
        }

        return $result;
    }
}
