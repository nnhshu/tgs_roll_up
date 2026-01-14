<?php
/**
 * Cron Service
 * Handles WordPress cron scheduling và execution
 *
 * @package TGS_Sync_Roll_Up
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class CronService
{
    /**
     * Cron hook name
     */
    const CRON_HOOK = 'tgs_sync_rollup_cron';
    const CLEANUP_HOOK = 'tgs_sync_rollup_cleanup';

    /**
     * @var CalculateDailyProductRollup
     */
    private $calculateRollUp;

    /**
     * @var CalculateDailyInventory
     */
    private $calculateInventory;

    /**
     * @var SyncToParentShop
     */
    private $syncToParent;

    /**
     * @var SyncInventoryToParentShop
     */
    private $syncInventoryToParent;

    /**
     * @var CalculateDailyOrder
     */
    private $calculateOrder;

    /**
     * @var SyncOrderToParentShop
     */
    private $syncOrderToParent;

    /**
     * @var CalculateDailyAccounting
     */
    private $calculateAccounting;

    /**
     * @var SyncAccountingToParentShop
     */
    private $syncAccountingToParent;

    /**
     * @var ConfigRepositoryInterface
     */
    private $configRepo;

    /**
     * @var DataSourceInterface
     */
    private $dataSource;

    /**
     * Constructor
     */
    public function __construct(
        CalculateDailyProductRollup $calculateRollUp,
        CalculateDailyInventory $calculateInventory,
        SyncToParentShop $syncToParent,
        SyncInventoryToParentShop $syncInventoryToParent,
        CalculateDailyOrder $calculateOrder,
        SyncOrderToParentShop $syncOrderToParent,
        CalculateDailyAccounting $calculateAccounting,
        SyncAccountingToParentShop $syncAccountingToParent,
        ConfigRepositoryInterface $configRepo,
        DataSourceInterface $dataSource
    ) {
        $this->calculateRollUp = $calculateRollUp;
        $this->calculateInventory = $calculateInventory;
        $this->syncToParent = $syncToParent;
        $this->syncInventoryToParent = $syncInventoryToParent;
        $this->calculateOrder = $calculateOrder;
        $this->syncOrderToParent = $syncOrderToParent;
        $this->calculateAccounting = $calculateAccounting;
        $this->syncAccountingToParent = $syncAccountingToParent;
        $this->configRepo = $configRepo;
        $this->dataSource = $dataSource;
    }

    /**
     * Schedule cron jobs
     */
    public function scheduleCrons(): void
    {
        // Main sync cron
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), 'hourly', self::CRON_HOOK);
        }

        // Daily cleanup
        if (!wp_next_scheduled(self::CLEANUP_HOOK)) {
            wp_schedule_event(time(), 'daily', self::CLEANUP_HOOK);
        }
    }

    /**
     * Unschedule cron jobs
     */
    public function unscheduleCrons(): void
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }

        $timestamp = wp_next_scheduled(self::CLEANUP_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CLEANUP_HOOK);
        }
    }

    /**
     * Run sync cron for current blog
     */
    public function runSyncCron(): void
    {
        error_log("CronService: Starting sync cron" . time());
        $blogId = get_current_blog_id();
        $date = current_time('Y-m-d');

        try {
            // BƯỚC 1: Lấy TẤT CẢ ledgers trong ngày với is_croned = 0 và local_ledger_status = 4 (CHỈ MỘT LẦN)
            $allLedgers = $this->dataSource->getLedgers($date, [], false);

            if (empty($allLedgers)) {
                error_log("CronService: No unprocessed ledgers found for date {$date}");
                return;
            }

            // BƯỚC 2: Phân loại ledgers theo type
            $ledgersByType = $this->groupLedgersByType($allLedgers);

            // BƯỚC 3: Tính toán roll-up cho từng loại
            $allLedgerIds = array_column($allLedgers, 'local_ledger_id');

            // 3.1. Calculate product roll-up (type 10, 11)
            if (!empty($ledgersByType[TGS_LEDGER_TYPE_SALES_ROLL_UP]) || !empty($ledgersByType[11])) {
                $productLedgers = array_merge(
                    $ledgersByType[TGS_LEDGER_TYPE_SALES_ROLL_UP] ?? [],
                    $ledgersByType[11] ?? []
                );
                $this->calculateRollUp->executeWithLedgers($blogId, $date, $productLedgers);
            }

            // 3.2. Calculate inventory (type 1, 2, 6)
            if (!empty($ledgersByType[TGS_LEDGER_TYPE_IMPORT_ROLL_UP]) ||
                !empty($ledgersByType[TGS_LEDGER_TYPE_EXPORT_ROLL_UP]) ||
                !empty($ledgersByType[TGS_LEDGER_TYPE_DAMAGE_ROLL_UP])) {
                $inventoryLedgers = array_merge(
                    $ledgersByType[TGS_LEDGER_TYPE_IMPORT_ROLL_UP] ?? [],
                    $ledgersByType[TGS_LEDGER_TYPE_EXPORT_ROLL_UP] ?? [],
                    $ledgersByType[TGS_LEDGER_TYPE_DAMAGE_ROLL_UP] ?? []
                );
                $this->calculateInventory->executeWithLedgers($blogId, $date, $inventoryLedgers);
            }

            // 3.3. Calculate orders (type 10)
            if (!empty($ledgersByType[TGS_LEDGER_TYPE_SALES_ROLL_UP])) {
                $this->calculateOrder->executeWithLedgers($blogId, $date, $ledgersByType[TGS_LEDGER_TYPE_SALES_ROLL_UP]);
            }

            // 3.4. Calculate accounting (type 7, 8)
            if (!empty($ledgersByType[TGS_LEDGER_TYPE_RECEIPT_ROLL_UP]) || !empty($ledgersByType[TGS_LEDGER_TYPE_PAYMENT_ROLL_UP])) {
                $accountingLedgers = array_merge(
                    $ledgersByType[TGS_LEDGER_TYPE_RECEIPT_ROLL_UP] ?? [],
                    $ledgersByType[TGS_LEDGER_TYPE_PAYMENT_ROLL_UP] ?? []
                );
                $this->calculateAccounting->executeWithLedgers($blogId, $date, $accountingLedgers);
            }

            // BƯỚC 4: Đánh dấu TẤT CẢ ledgers đã xử lý
            if (!empty($allLedgerIds)) {
                $marked = $this->dataSource->markLedgersAsProcessed($allLedgerIds);
            }

            // BƯỚC 5: Sync to parent
            $this->syncToParent->execute($blogId, $date);
            $this->syncInventoryToParent->syncByDate($blogId, $date);
            $this->syncOrderToParent->syncByDate($blogId, $date);
            $this->syncAccountingToParent->syncByDate($blogId, $date);

            $this->logCron([
                'blog_id' => $blogId,
                'date' => $date,
                'status' => 'success',
                'message' => 'Cron completed successfully',
                'ledgers_processed' => count($allLedgerIds),
            ]);

        } catch (Exception $e) {
            $this->logCron([
                'blog_id' => $blogId,
                'date' => $date,
                'status' => 'failed',
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Group ledgers by type
     *
     * @param array $ledgers All ledgers
     * @return array Ledgers grouped by type
     */
    private function groupLedgersByType(array $ledgers): array
    {
        $grouped = [];
        foreach ($ledgers as $ledger) {
            $type = intval($ledger['local_ledger_type']);
            if (!isset($grouped[$type])) {
                $grouped[$type] = [];
            }
            $grouped[$type][] = $ledger;
        }
        return $grouped;
    }

    /**
     * Run cleanup cron
     */
    public function runCleanupCron(): void
    {
        // Cleanup old logs (keep 30 days)
        $blogs = get_sites(['number' => 1000]);

        foreach ($blogs as $blog) {
            $logOption = 'tgs_sync_log_' . $blog->blog_id;
            $logs = get_option($logOption, []);

            if (count($logs) > 100) {
                $logs = array_slice($logs, -100);
                update_option($logOption, $logs);
            }
        }
    }

    /**
     * Update cron frequency
     *
     * @param string $frequency Frequency: every_15_minutes, every_30_minutes, hourly, daily
     */
    public function updateCronFrequency(string $frequency): void
    {
        // Unschedule old
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }

        // Schedule new
        wp_schedule_event(time(), $frequency, self::CRON_HOOK);
    }

    /**
     * Get next scheduled time
     *
     * @return array Schedule info
     */
    public function getNextScheduled(): array
    {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);

        return [
            'next_run' => $timestamp ? date('Y-m-d H:i:s', $timestamp) : null,
            'interval' => $timestamp ? $this->getScheduleInterval($timestamp) : null,
        ];
    }

    /**
     * Sync specific date
     *
     * @param int $blogId Blog ID
     * @param string $date Date
     * @param string $syncType Sync type: all, products, inventory
     * @return array Result
     */
    public function syncSpecificDate(int $blogId, string $date, string $syncType = 'all'): array
    {
        $result = [];
        $allLedgerIds = [];

        try {
            if ($syncType === 'all' || $syncType === 'products') {
                $productResult = $this->calculateRollUp->execute($blogId, $date);
                $result['products'] = $productResult;
                if (!empty($productResult['ledger_ids'])) {
                    $allLedgerIds = array_merge($allLedgerIds, $productResult['ledger_ids']);
                }
            }

            if ($syncType === 'all' || $syncType === 'inventory') {
                $inventoryResult = $this->calculateInventory->execute($blogId, $date);
                $result['inventory'] = $inventoryResult;
                if (!empty($inventoryResult['ledger_ids'])) {
                    $allLedgerIds = array_merge($allLedgerIds, $inventoryResult['ledger_ids']);
                }
            }

            if ($syncType === 'all' || $syncType === 'orders') {
                $orderResult = $this->calculateOrder->execute($blogId, $date);
                $result['orders'] = $orderResult;
                if (!empty($orderResult['ledger_ids'])) {
                    $allLedgerIds = array_merge($allLedgerIds, $orderResult['ledger_ids']);
                }
            }

            if ($syncType === 'all' || $syncType === 'accounting') {
                $accountingResult = $this->calculateAccounting->execute($blogId, $date);
                $result['accounting'] = $accountingResult;
                if (!empty($accountingResult['ledger_ids'])) {
                    $allLedgerIds = array_merge($allLedgerIds, $accountingResult['ledger_ids']);
                }
            }

            // Đánh cờ is_croned = 1 sau khi tất cả roll-up xong
            if (!empty($allLedgerIds)) {
                $allLedgerIds = array_unique($allLedgerIds);
                $this->dataSource->markLedgersAsProcessed($allLedgerIds);
                $result['ledgers_processed'] = count($allLedgerIds);
            }

            if ($syncType === 'all') {
                $result['sync_products'] = $this->syncToParent->execute($blogId, $date);
                $result['sync_inventory'] = $this->syncInventoryToParent->syncByDate($blogId, $date);
                $result['sync_orders'] = $this->syncOrderToParent->syncByDate($blogId, $date);
                $result['sync_accounting'] = $this->syncAccountingToParent->syncByDate($blogId, $date);
            }

            $result['status'] = 'success';
        } catch (Exception $e) {
            $result['status'] = 'failed';
            $result['error'] = $e->getMessage();
        }

        return $result;
    }

    /**
     * Rebuild date range
     *
     * @param int $blogId Blog ID
     * @param string $startDate Start date
     * @param string $endDate End date
     * @param bool $syncToParents Whether to sync to parents
     * @return array Result
     */
    public function rebuildDateRange(int $blogId, string $startDate, string $endDate, bool $syncToParents = true): array
    {
        $result = [
            'total' => 0,
            'success' => 0,
            'failed' => 0,
            'details' => [],
        ];

        $current = strtotime($startDate);
        $end = strtotime($endDate);

        while ($current <= $end) {
            $date = date('Y-m-d', $current);
            $result['total']++;

            try {
                $allLedgerIds = [];

                // Calculate all roll-ups
                $productResult = $this->calculateRollUp->execute($blogId, $date);
                if (!empty($productResult['ledger_ids'])) {
                    $allLedgerIds = array_merge($allLedgerIds, $productResult['ledger_ids']);
                }

                $inventoryResult = $this->calculateInventory->execute($blogId, $date);
                if (!empty($inventoryResult['ledger_ids'])) {
                    $allLedgerIds = array_merge($allLedgerIds, $inventoryResult['ledger_ids']);
                }

                $orderResult = $this->calculateOrder->execute($blogId, $date);
                if (!empty($orderResult['ledger_ids'])) {
                    $allLedgerIds = array_merge($allLedgerIds, $orderResult['ledger_ids']);
                }

                $accountingResult = $this->calculateAccounting->execute($blogId, $date);
                if (!empty($accountingResult['ledger_ids'])) {
                    $allLedgerIds = array_merge($allLedgerIds, $accountingResult['ledger_ids']);
                }

                // Đánh cờ is_croned = 1 sau khi tất cả roll-up xong
                if (!empty($allLedgerIds)) {
                    $allLedgerIds = array_unique($allLedgerIds);
                    $this->dataSource->markLedgersAsProcessed($allLedgerIds);
                }

                if ($syncToParents) {
                    $this->syncToParent->execute($blogId, $date);
                    $this->syncInventoryToParent->syncByDate($blogId, $date);
                    $this->syncOrderToParent->syncByDate($blogId, $date);
                    $this->syncAccountingToParent->syncByDate($blogId, $date);
                }

                $result['success']++;
            } catch (Exception $e) {
                $result['failed']++;
                $result['details'][$date] = $e->getMessage();
            }

            $current = strtotime('+1 day', $current);
        }

        return $result;
    }

    /**
     * Log cron execution
     *
     * @param array $data Log data
     */
    private function logCron(array $data): void
    {
        $logOption = 'tgs_cron_log_' . $data['blog_id'];
        $logs = get_option($logOption, []);

        if (count($logs) >= 100) {
            array_shift($logs);
        }

        $logs[] = array_merge($data, [
            'timestamp' => current_time('mysql'),
        ]);

        update_option($logOption, $logs);
    }

    /**
     * Get schedule interval
     *
     * @param int $timestamp Timestamp
     * @return string|null Interval
     */
    private function getScheduleInterval(int $timestamp): ?string
    {
        $crons = _get_cron_array();

        foreach ($crons as $time => $cron) {
            if (isset($cron[self::CRON_HOOK])) {
                foreach ($cron[self::CRON_HOOK] as $event) {
                    return $event['schedule'] ?? null;
                }
            }
        }

        return null;
    }

    /**
     * Get recent cron logs
     *
     * @param int $limit Limit
     * @return array Logs
     */
    public function getRecentCronLogs(int $limit = 10): array
    {
        $blogId = get_current_blog_id();
        $logOption = 'tgs_cron_log_' . $blogId;
        $logs = get_option($logOption, []);

        return array_slice(array_reverse($logs), 0, $limit);
    }
}

add_filter('cron_schedules', function($schedules) {
    $schedules['every_minute'] = array(
        'interval' => 60,
        'display'  => __('Every Minute')
    );

    // Custom intervals for sync
    $schedules['every_three_minutes'] = array(
        'interval' => 180,
        'display'  => __('Every 3 Minutes')
    );

    $schedules['every_fifteen_minutes'] = array(
        'interval' => 900,  // 15 * 60
        'display'  => __('Every 15 Minutes')
    );

    $schedules['every_thirty_minutes'] = array(
        'interval' => 1800,  // 30 * 60
        'display'  => __('Every 30 Minutes')
    );

    $schedules['every_two_hours'] = array(
        'interval' => 7200,  // 2 * 60 * 60
        'display'  => __('Every 2 Hours')
    );

    $schedules['every_four_hours'] = array(
        'interval' => 14400,  // 4 * 60 * 60
        'display'  => __('Every 4 Hours')
    );

    $schedules['every_six_hours'] = array(
        'interval' => 21600,  // 6 * 60 * 60
        'display'  => __('Every 6 Hours')
    );

    return $schedules;
});
