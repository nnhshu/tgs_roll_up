<?php
/**
 * Calculate Daily Accounting Use Case
 * Business logic cho việc tính toán accounting roll-up hàng ngày (thu chi)
 *
 * @package TGS_Sync_Roll_Up
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class CalculateDailyAccounting
{
    /**
     * @var wpdb
     */
    private $wpdb;

    /**
     * @var BlogContext
     */
    private $blogContext;

    /**
     * @var DataSourceInterface
     */
    private $dataSource;

    /**
     * Constructor
     */
    public function __construct(BlogContext $blogContext, DataSourceInterface $dataSource)
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->blogContext = $blogContext;
        $this->dataSource = $dataSource;
    }

    /**
     * Execute accounting calculation (query ledgers from database)
     *
     * @param int $blogId Blog ID
     * @param string $date Ngày (Y-m-d)
     * @return array Result with saved count and ledger_ids
     */
    public function execute(int $blogId, string $date): array
    {
        return $this->blogContext->executeInBlog($blogId, function() use ($blogId, $date) {
            // Lấy ledgers với type 7 (thu) và 8 (chi)
            $types = [
                TGS_LEDGER_TYPE_RECEIPT_ROLL_UP,  // 7 - Thu tiền
                TGS_LEDGER_TYPE_PAYMENT_ROLL_UP,  // 8 - Chi tiền
            ];

            $ledgers = $this->dataSource->getLedgers($date, $types, false);

            if (empty($ledgers)) {
                return ['saved_count' => 0, 'ledger_ids' => []];
            }

            return $this->processLedgers($blogId, $date, $ledgers);
        });
    }

    /**
     * Execute accounting calculation with pre-fetched ledgers
     *
     * @param int $blogId Blog ID
     * @param string $date Ngày (Y-m-d)
     * @param array $ledgers Pre-fetched ledgers (type 7 and 8)
     * @return array Result
     */
    public function executeWithLedgers(int $blogId, string $date, array $ledgers): array
    {
        return $this->blogContext->executeInBlog($blogId, function() use ($blogId, $date, $ledgers) {
            if (empty($ledgers)) {
                return ['saved_count' => 0];
            }
            return $this->processLedgers($blogId, $date, $ledgers);
        });
    }

    /**
     * Process accounting ledgers and save roll-up data
     *
     * @param int $blogId Blog ID
     * @param string $date Date
     * @param array $ledgers Ledgers to process
     * @return array Result
     */
    private function processLedgers(int $blogId, string $date, array $ledgers): array
    {
        // Parse date
        $dateParts = explode('-', $date);
        $year = intval($dateParts[0]);
        $month = intval($dateParts[1]);
        $day = intval($dateParts[2]);

        $accountingTable = $this->wpdb->prefix . 'accounting_roll_up';

        // Lấy ledger IDs
        $ledgerIds = array_column($ledgers, 'local_ledger_id');

        // Tính tổng thu chi
        $totalIncome = 0;
        $totalExpense = 0;

        foreach ($ledgers as $ledger) {
            $ledgerType = intval($ledger['local_ledger_type']);
            $amount = floatval($ledger['local_ledger_total_amount'] ?? 0);

            if ($ledgerType === TGS_LEDGER_TYPE_RECEIPT_ROLL_UP) {
                // Type 7 = Thu
                $totalIncome += $amount;
            } elseif ($ledgerType === TGS_LEDGER_TYPE_PAYMENT_ROLL_UP) {
                // Type 8 = Chi
                $totalExpense += $amount;
            }
        }

        // Save accounting roll-up using INSERT ... ON DUPLICATE KEY UPDATE để cộng dồn
        $createdAt = current_time('mysql');
        $updatedAt = current_time('mysql');

        $this->wpdb->query($this->wpdb->prepare(
            "INSERT INTO {$accountingTable}
            (blog_id, roll_up_date, roll_up_day, roll_up_month, roll_up_year, total_income, total_expense, created_at, updated_at)
            VALUES (%d, %s, %d, %d, %d, %f, %f, %s, %s)
            ON DUPLICATE KEY UPDATE
                total_income = total_income + VALUES(total_income),
                total_expense = total_expense + VALUES(total_expense),
                updated_at = VALUES(updated_at)",
            $blogId,
            $date,
            $day,
            $month,
            $year,
            $totalIncome,
            $totalExpense,
            $createdAt,
            $updatedAt
        ));

        return [
            'saved_count' => 1,
            'ledger_ids' => $ledgerIds,
            'total_income' => $totalIncome,
            'total_expense' => $totalExpense,
        ];
    }
}
