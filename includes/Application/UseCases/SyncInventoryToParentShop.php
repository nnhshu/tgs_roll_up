<?php
/**
 * Sync Inventory To Parent Shop Use Case
 * Business logic cho việc đồng bộ inventory lên shop cha
 *
 * @package TGS_Sync_Roll_Up
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SyncInventoryToParentShop
{
    /**
     * @var InventoryRollUpRepository
     */
    private $inventoryRepo;

    /**
     * @var ConfigRepositoryInterface
     */
    private $configRepo;

    /**
     * @var BlogContext
     */
    private $blogContext;

    /**
     * Constructor
     *
     * @param InventoryRollUpRepository $inventoryRepo
     * @param ConfigRepositoryInterface $configRepo
     * @param BlogContext $blogContext
     */
    public function __construct(
        InventoryRollUpRepository $inventoryRepo,
        ConfigRepositoryInterface $configRepo,
        BlogContext $blogContext
    ) {
        $this->inventoryRepo = $inventoryRepo;
        $this->configRepo = $configRepo;
        $this->blogContext = $blogContext;
    }

    /**
     * Execute inventory sync
     *
     * @param int $sourceBlogId Shop con
     * @param int $year Year
     * @param int $month Month
     * @return array Kết quả sync
     */
    public function execute(int $sourceBlogId, int $year, int $month): array
    {
        $result = [
            'success' => [],
            'failed' => [],
            'source_blog_id' => $sourceBlogId,
            'year' => $year,
            'month' => $month,
            'synced_at' => current_time('mysql'),
        ];

        // Lấy parent blog ID
        $parentBlogId = $this->configRepo->getParentBlogId($sourceBlogId);

        if (!$parentBlogId) {
            $result['message'] = 'No parent shop configured';
            return $result;
        }

        // Kiểm tra approval status
        $config = $this->configRepo->getConfig($sourceBlogId);
        if (!$config || $config->approval_status !== 'approved') {
            $result['message'] = 'Parent relationship not approved';
            return $result;
        }

        // Kiểm tra parent blog có tồn tại không
        if (!$this->blogContext->blogExists($parentBlogId)) {
            $result['failed'][] = [
                'parent_blog_id' => $parentBlogId,
                'error' => 'Parent blog does not exist',
            ];
            return $result;
        }

        // Lấy TẤT CẢ inventory records của shop con cho tháng này (cả daily và monthly total)
        $firstDayOfMonth = sprintf('%04d-%02d-01', $year, $month);
        $lastDayOfMonth = sprintf('%04d-%02d-%02d', $year, $month, cal_days_in_month(CAL_GREGORIAN, $month, $year));

        $sourceRecords = $this->inventoryRepo->findByDateRange($sourceBlogId, $firstDayOfMonth, $lastDayOfMonth);

        if (empty($sourceRecords)) {
            $result['message'] = 'No inventory data found for source blog';
            return $result;
        }

        // Sync từng record lên parent
        $syncedCount = 0;

        try {
            foreach ($sourceRecords as $record) {
                // Tạo data để sync (giữ nguyên blog_id của shop con)
                $parentData = [
                    'blog_id' => $sourceBlogId,  // QUAN TRỌNG: giữ blog_id của con
                    'local_product_name_id' => $record['local_product_name_id'],
                    'global_product_name_id' => $record['global_product_name_id'],
                    'roll_up_date' => $record['roll_up_date'],
                    'roll_up_day' => $record['roll_up_day'],  // Giữ nguyên roll_up_day (có thể là 0 hoặc ngày cụ thể)
                    'inventory_qty' => $record['inventory_qty'],
                    'inventory_value' => $record['inventory_value'],
                ];

                // Sync meta
                if (!empty($record['meta'])) {
                    $parentData['meta'] = $record['meta'];
                }

                // Lưu vào parent blog (overwrite = true để thay thế data cũ)
                $inventoryId = $this->blogContext->executeInBlog($parentBlogId, function() use ($parentData) {
                    return $this->inventoryRepo->save($parentData, true);
                });

                if ($inventoryId) {
                    $syncedCount++;
                }
            }

            $result['success'][] = [
                'parent_blog_id' => $parentBlogId,
                'records_synced' => $syncedCount,
            ];

        } catch (Exception $e) {
            $result['failed'][] = [
                'parent_blog_id' => $parentBlogId,
                'error' => $e->getMessage(),
            ];
        }

        $result['total_synced'] = $syncedCount;

        // Log kết quả
        $this->logSyncResult($sourceBlogId, $result);

        return $result;
    }

    /**
     * Sync inventory by date
     * This will sync the monthly total that includes the given date
     *
     * @param int $sourceBlogId Shop con
     * @param string $date Date (Y-m-d)
     * @return array Result
     */
    public function syncByDate(int $sourceBlogId, string $date): array
    {
        $dateParts = explode('-', $date);
        $year = intval($dateParts[0]);
        $month = intval($dateParts[1]);

        return $this->execute($sourceBlogId, $year, $month);
    }

    /**
     * Log kết quả sync
     *
     * @param int $blogId
     * @param array $result
     */
    private function logSyncResult(int $blogId, array $result): void
    {
        $logOption = 'tgs_sync_inventory_log_' . $blogId;
        $existingLogs = get_option($logOption, []);

        // Giữ tối đa 100 logs
        if (count($existingLogs) >= 100) {
            array_shift($existingLogs);
        }

        $existingLogs[] = [
            'timestamp' => current_time('mysql'),
            'year' => $result['year'],
            'month' => $result['month'],
            'success_count' => count($result['success']),
            'failed_count' => count($result['failed']),
            'details' => $result,
        ];

        update_option($logOption, $existingLogs);
    }
}
