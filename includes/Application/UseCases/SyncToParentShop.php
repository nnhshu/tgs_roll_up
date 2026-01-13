<?php
/**
 * Sync To Parent Shop Use Case
 * Business logic cho việc đồng bộ dữ liệu lên shop cha
 *
 * @package TGS_Sync_Roll_Up
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SyncToParentShop
{
    /**
     * @var RollUpRepositoryInterface
     */
    private $rollUpRepo;

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
     * @param RollUpRepositoryInterface $rollUpRepo
     * @param ConfigRepositoryInterface $configRepo
     * @param BlogContext $blogContext
     */
    public function __construct(
        RollUpRepositoryInterface $rollUpRepo,
        ConfigRepositoryInterface $configRepo,
        BlogContext $blogContext
    ) {
        $this->rollUpRepo = $rollUpRepo;
        $this->configRepo = $configRepo;
        $this->blogContext = $blogContext;
    }

    /**
     * Execute sync
     *
     * @param int $sourceBlogId Shop con
     * @param string $date Ngày
     * @return array Kết quả sync
     */
    public function execute(int $sourceBlogId, string $date): array
    {
        $result = [
            'success' => [],
            'failed' => [],
            'source_blog_id' => $sourceBlogId,
            'date' => $date,
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

        // Lấy TẤT CẢ roll-up records của shop con cho ngày này
        $sourceRecords = $this->rollUpRepo->findByBlogAndDate($sourceBlogId, $date);

        if (empty($sourceRecords)) {
            $result['message'] = 'No roll-up data found for source blog';
            return $result;
        }

        // Sync từng record lên parent
        $syncedCount = 0;

        try {
            foreach ($sourceRecords as $record) {
                // Tạo data để sync (giữ nguyên blog_id của shop con)
                $parentData = [
                    'blog_id' => $sourceBlogId,  // QUAN TRỌNG: giữ blog_id của con
                    'roll_up_date' => $record['roll_up_date'],
                    'local_product_name_id' => $record['local_product_name_id'],
                    'global_product_name_id' => $record['global_product_name_id'],
                    'amount_after_tax' => $record['amount_after_tax'],
                    'tax' => $record['tax'],
                    'quantity' => $record['quantity'],
                    'type' => $record['type'],
                ];

                // Sync meta (lot_ids)
                if (!empty($record['meta'])) {
                    $metaData = json_decode($record['meta'], true);
                    if (is_array($metaData) && isset($metaData['lot_ids'])) {
                        $parentData['lot_ids'] = $metaData['lot_ids'];
                    }
                }

                // Lưu vào parent blog (overwrite = true để thay thế data cũ)
                $rollUpId = $this->blogContext->executeInBlog($parentBlogId, function() use ($parentData) {
                    return $this->rollUpRepo->save($parentData, true);
                });

                if ($rollUpId) {
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
     * Log kết quả sync
     *
     * @param int $blogId
     * @param array $result
     */
    private function logSyncResult(int $blogId, array $result): void
    {
        $logOption = 'tgs_sync_log_' . $blogId;
        $existingLogs = get_option($logOption, []);

        // Giữ tối đa 100 logs
        if (count($existingLogs) >= 100) {
            array_shift($existingLogs);
        }

        $existingLogs[] = [
            'timestamp' => current_time('mysql'),
            'date' => $result['date'],
            'success_count' => count($result['success']),
            'failed_count' => count($result['failed']),
            'details' => $result,
        ];

        update_option($logOption, $existingLogs);
    }
}
