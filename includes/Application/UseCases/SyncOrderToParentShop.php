<?php
/**
 * Sync Order To Parent Shop Use Case
 * Business logic cho việc đồng bộ orders lên shop cha
 *
 * @package TGS_Sync_Roll_Up
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SyncOrderToParentShop
{
    /**
     * @var OrderRollUpRepository
     */
    private $orderRepo;

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
     * @param OrderRollUpRepository $orderRepo
     * @param ConfigRepositoryInterface $configRepo
     * @param BlogContext $blogContext
     */
    public function __construct(
        OrderRollUpRepository $orderRepo,
        ConfigRepositoryInterface $configRepo,
        BlogContext $blogContext
    ) {
        $this->orderRepo = $orderRepo;
        $this->configRepo = $configRepo;
        $this->blogContext = $blogContext;
    }

    /**
     * Execute order sync
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

        // Lấy TẤT CẢ order records của shop con cho tháng này (cả daily và monthly total)
        $firstDayOfMonth = sprintf('%04d-%02d-01', $year, $month);
        $lastDayOfMonth = sprintf('%04d-%02d-%02d', $year, $month, cal_days_in_month(CAL_GREGORIAN, $month, $year));

        $sourceRecords = $this->orderRepo->findByDateRange($sourceBlogId, $firstDayOfMonth, $lastDayOfMonth);

        if (empty($sourceRecords)) {
            $result['message'] = 'No order data found for source blog';
            return $result;
        }

        // Sync từng record lên parent
        $syncedCount = 0;
        $totalOrderCount = 0;
        $totalOrderValue = 0;

        try {
            foreach ($sourceRecords as $record) {
                // Tạo data để sync (giữ nguyên blog_id của shop con)
                $parentData = [
                    'blog_id' => $sourceBlogId,  // QUAN TRỌNG: giữ blog_id của con
                    'roll_up_date' => $record['roll_up_date'],
                    'roll_up_day' => $record['roll_up_day'],  // Giữ nguyên roll_up_day (có thể là 0 hoặc ngày cụ thể)
                    'count' => $record['count'],
                    'value' => $record['value'],
                ];

                // Sync meta
                if (!empty($record['meta'])) {
                    $parentData['meta'] = $record['meta'];
                }

                // Lưu vào parent blog (overwrite = true để thay thế data cũ)
                $orderId = $this->blogContext->executeInBlog($parentBlogId, function() use ($parentData) {
                    return $this->orderRepo->save($parentData, true);
                });

                if ($orderId) {
                    $syncedCount++;
                    $totalOrderCount += intval($record['count']);
                    $totalOrderValue += floatval($record['value']);
                }
            }

            $result['success'][] = [
                'parent_blog_id' => $parentBlogId,
                'records_synced' => $syncedCount,
                'order_count' => $totalOrderCount,
                'order_value' => $totalOrderValue,
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
     * Sync orders by date
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
        $logOption = 'tgs_sync_order_log_' . $blogId;
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
