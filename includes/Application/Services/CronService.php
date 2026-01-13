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
     * @var ConfigRepositoryInterface
     */
    private $configRepo;

    /**
     * Constructor
     */
    public function __construct(
        CalculateDailyProductRollup $calculateRollUp,
        CalculateDailyInventory $calculateInventory,
        SyncToParentShop $syncToParent,
        ConfigRepositoryInterface $configRepo
    ) {
        $this->calculateRollUp = $calculateRollUp;
        $this->calculateInventory = $calculateInventory;
        $this->syncToParent = $syncToParent;
        $this->configRepo = $configRepo;
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
        $blogId = get_current_blog_id();
        $date = current_time('Y-m-d');

        try {
            // 1. Calculate roll-up
            $this->calculateRollUp->execute($blogId, $date);

            // 2. Calculate inventory
            $this->calculateInventory->execute($blogId, $date);

            // 3. Sync to parent (if configured)
            $this->syncToParent->execute($blogId, $date);

            $this->logCron([
                'blog_id' => $blogId,
                'date' => $date,
                'status' => 'success',
                'message' => 'Cron completed successfully',
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

        try {
            if ($syncType === 'all' || $syncType === 'products') {
                $result['products'] = $this->calculateRollUp->execute($blogId, $date);
            }

            if ($syncType === 'all' || $syncType === 'inventory') {
                $result['inventory'] = $this->calculateInventory->execute($blogId, $date);
            }

            if ($syncType === 'all') {
                $result['sync'] = $this->syncToParent->execute($blogId, $date);
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
                $this->calculateRollUp->execute($blogId, $date);
                $this->calculateInventory->execute($blogId, $date);

                if ($syncToParents) {
                    $this->syncToParent->execute($blogId, $date);
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
