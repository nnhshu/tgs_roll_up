<?php
/**
 * Product Roll-Up Repository
 * Implementation của RollUpRepositoryInterface cho product_roll_up table
 *
 * @package TGS_Sync_Roll_Up
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once TGS_SYNC_ROLL_UP_PATH . 'includes/Core/Interfaces/RollUpRepositoryInterface.php';

class ProductRollUpRepository implements RollUpRepositoryInterface
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
     * Ensure table exists
     */
    private function ensureTableExists(): void
    {
        $table = $this->wpdb->prefix . 'product_roll_up';
        $exists = $this->wpdb->get_var($this->wpdb->prepare("SHOW TABLES LIKE %s", $table));

        if (!$exists) {
            require_once TGS_SYNC_ROLL_UP_PATH . 'includes/class-database.php';
            TGS_Sync_Roll_Up_Database::create_tables();
        }
    }

    /**
     * {@inheritdoc}
     */
    public function save(array $data, bool $overwrite = false): int
    {
        $this->ensureTableExists();
        $table = $this->wpdb->prefix . 'product_roll_up';

        // Prepare data
        $insert_data = [
            'blog_id' => $data['blog_id'] ?? get_current_blog_id(),
            'roll_up_date' => $data['roll_up_date'] ?? current_time('Y-m-d'),
            'local_product_name_id' => $data['local_product_name_id'] ?? 0,
            'global_product_name_id' => $data['global_product_name_id'] ?? null,
            'amount_after_tax' => $data['amount_after_tax'] ?? 0,
            'tax' => $data['tax'] ?? 0,
            'quantity' => $data['quantity'] ?? 0,
            'type' => $data['type'] ?? 0,
            'source' => $data['source'] ?? 0,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ];

        // Parse roll_up_date để lấy day, month, year
        $date_parts = explode('-', $insert_data['roll_up_date']);
        $insert_data['roll_up_year'] = intval($date_parts[0]);
        $insert_data['roll_up_month'] = intval($date_parts[1]);
        $insert_data['roll_up_day'] = intval($date_parts[2]);

        // Meta (lot_ids và ledger_ids)
        $meta = [];
        if (!empty($data['lot_ids']) && is_array($data['lot_ids'])) {
            $meta['lot_ids'] = $data['lot_ids'];
        }
        if (!empty($data['ledger_ids']) && is_array($data['ledger_ids'])) {
            $meta['ledger_ids'] = $data['ledger_ids'];
        }
        if (!empty($meta)) {
            $insert_data['meta'] = json_encode($meta);
        }

        // ON DUPLICATE KEY UPDATE
        $on_duplicate = $overwrite
            ? "amount_after_tax = VALUES(amount_after_tax),
               tax = VALUES(tax),
               quantity = VALUES(quantity),
               type = VALUES(type),
               source = VALUES(source),
               meta = VALUES(meta),
               updated_at = VALUES(updated_at)"
            : "amount_after_tax = amount_after_tax + VALUES(amount_after_tax),
               tax = tax + VALUES(tax),
               quantity = quantity + VALUES(quantity),
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

        return $this->wpdb->insert_id ?: 1; // Return insert_id hoặc 1 nếu là update
    }

    /**
     * {@inheritdoc}
     */
    public function findByBlogAndDate(int $blogId, string $date): ?array
    {
        $this->ensureTableExists();
        $table = $this->wpdb->prefix . 'product_roll_up';
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
     * {@inheritdoc}
     */
    public function sumByDateRange(int $blogId, string $fromDate, string $toDate): array
    {
        $this->ensureTableExists();
        $table = $this->wpdb->prefix . 'product_roll_up';

        $result = $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN type = %d THEN amount_after_tax ELSE 0 END), 0) as sales,
                COALESCE(SUM(CASE WHEN type = %d THEN amount_after_tax ELSE 0 END), 0) as returns,
                COALESCE(SUM(CASE WHEN type = %d THEN amount_after_tax ELSE 0 END), 0) as purchases,
                COALESCE(SUM(quantity), 0) as total_quantity
             FROM {$table}
             WHERE blog_id = %d
             AND roll_up_date BETWEEN %s AND %s",
            TGS_LEDGER_TYPE_SALES_ROLL_UP,
            TGS_LEDGER_TYPE_RETURN_ROLL_UP,
            TGS_LEDGER_TYPE_PURCHASE_ROLL_UP,
            $blogId,
            $fromDate,
            $toDate
        ), ARRAY_A);

        if (!$result) {
            return [
                'revenue' => 0,
                'sales' => 0,
                'returns' => 0,
                'purchases' => 0,
                'total_quantity' => 0,
            ];
        }

        $result['revenue'] = $result['sales'] - $result['returns'];
        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function findByDateRange(int $blogId, string $fromDate, string $toDate): array
    {
        $this->ensureTableExists();
        $table = $this->wpdb->prefix . 'product_roll_up';

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
     * {@inheritdoc}
     */
    public function deleteByDateRange(int $blogId, string $fromDate, string $toDate): bool
    {
        $this->ensureTableExists();
        $table = $this->wpdb->prefix . 'product_roll_up';

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
     * {@inheritdoc}
     */
    public function saveMeta(int $rollUpId, array $meta): bool
    {
        // Product roll-up lưu meta trong cùng bảng (không có separate meta table)
        // Nếu cần, có thể mở rộng sau
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getMeta(int $rollUpId): ?array
    {
        $this->ensureTableExists();
        $table = $this->wpdb->prefix . 'product_roll_up';

        $meta = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT meta FROM {$table} WHERE roll_up_id = %d",
            $rollUpId
        ));

        return $meta ? json_decode($meta, true) : null;
    }
}
