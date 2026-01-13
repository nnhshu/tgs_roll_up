<?php
/**
 * Accounting Roll-Up Repository
 * Repository cho accounting_roll_up table
 *
 * @package TGS_Sync_Roll_Up
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class AccountingRollUpRepository
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
     * Ensure accounting_roll_up table exists
     * Tự động tạo bảng nếu chưa tồn tại
     */
    private function ensureTableExists(): void
    {
        $table = $this->wpdb->prefix . 'accounting_roll_up';

        // Check if table exists
        $table_exists = $this->wpdb->get_var("SHOW TABLES LIKE '{$table}'");

        if (!$table_exists) {
            error_log("AccountingRollUpRepository: Table {$table} does not exist, creating it now...");

            // Create table using the Database class method
            $blog_id = get_current_blog_id();
            TGS_Sync_Roll_Up_Database::create_accounting_roll_up_table($blog_id);

            error_log("AccountingRollUpRepository: Table {$table} created successfully");
        }
    }

    /**
     * Save accounting record
     *
     * @param array $data Accounting data
     * @return int Record ID
     */
    public function save(array $data): int
    {
        $this->ensureTableExists();
        $table = $this->wpdb->prefix . 'accounting_roll_up';

        // Prepare data
        $insert_data = [
            'blog_id' => $data['blog_id'] ?? get_current_blog_id(),
            'roll_up_date' => $data['roll_up_date'] ?? current_time('Y-m-d'),
            'total_income' => $data['total_income'] ?? 0,
            'total_expense' => $data['total_expense'] ?? 0,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ];

        // Parse roll_up_date để lấy day, month, year
        $date_parts = explode('-', $insert_data['roll_up_date']);
        $insert_data['roll_up_year'] = intval($date_parts[0]);
        $insert_data['roll_up_month'] = intval($date_parts[1]);
        $insert_data['roll_up_day'] = intval($date_parts[2]);

        // Meta
        if (!empty($data['meta'])) {
            $insert_data['meta'] = is_string($data['meta']) ? $data['meta'] : json_encode($data['meta']);
        }

        // ON DUPLICATE KEY UPDATE - cộng dồn
        $on_duplicate = "total_income = total_income + VALUES(total_income),
                         total_expense = total_expense + VALUES(total_expense),
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
     * Find accounting records by blog and date
     *
     * @param int $blogId Blog ID
     * @param string $date Date (Y-m-d)
     * @return array|null Records
     */
    public function findByBlogAndDate(int $blogId, string $date): ?array
    {
        $this->ensureTableExists();
        $table = $this->wpdb->prefix . 'accounting_roll_up';
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
     * Find accounting records by date range
     *
     * @param int $blogId Blog ID
     * @param string $fromDate From date
     * @param string $toDate To date
     * @return array Records
     */
    public function findByDateRange(int $blogId, string $fromDate, string $toDate): array
    {
        $this->ensureTableExists();
        $table = $this->wpdb->prefix . 'accounting_roll_up';

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
     * Delete accounting records by date range
     *
     * @param int $blogId Blog ID
     * @param string $fromDate From date
     * @param string $toDate To date
     * @return bool Success
     */
    public function deleteByDateRange(int $blogId, string $fromDate, string $toDate): bool
    {
        $this->ensureTableExists();
        $table = $this->wpdb->prefix . 'accounting_roll_up';

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
     * Get total income and expense for a period
     *
     * @param int $blogId Blog ID
     * @param int $year Year
     * @param int $month Month (optional, null for yearly)
     * @return array|null Totals
     */
    public function getTotals(int $blogId, int $year, ?int $month = null): ?array
    {
        $this->ensureTableExists();
        $table = $this->wpdb->prefix . 'accounting_roll_up';

        $query = "SELECT
                    SUM(total_income) as total_income,
                    SUM(total_expense) as total_expense
                  FROM {$table}
                  WHERE blog_id = %d
                  AND roll_up_year = %d";

        $params = [$blogId, $year];

        if ($month !== null) {
            $query .= " AND roll_up_month = %d";
            $params[] = $month;
        }

        return $this->wpdb->get_row(
            $this->wpdb->prepare($query, ...$params),
            ARRAY_A
        );
    }
}
