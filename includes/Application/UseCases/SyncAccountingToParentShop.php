<?php
/**
 * Sync Accounting To Parent Shop Use Case
 * Business logic cho việc đồng bộ accounting (thu chi) lên shop cha
 *
 * @package TGS_Sync_Roll_Up
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SyncAccountingToParentShop
{
    /**
     * @var AccountingRollUpRepository
     */
    private $accountingRepo;

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
     * @param AccountingRollUpRepository $accountingRepo
     * @param ConfigRepositoryInterface $configRepo
     * @param BlogContext $blogContext
     */
    public function __construct(
        AccountingRollUpRepository $accountingRepo,
        ConfigRepositoryInterface $configRepo,
        BlogContext $blogContext
    ) {
        $this->accountingRepo = $accountingRepo;
        $this->configRepo = $configRepo;
        $this->blogContext = $blogContext;
    }

    /**
     * Sync accounting data by specific date
     *
     * @param int $sourceBlogId Shop con
     * @param string $date Date (Y-m-d)
     * @return array Kết quả sync
     */
    public function syncByDate(int $sourceBlogId, string $date): array
    {
        $result = [
            'success' => false,
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
            $result['message'] = 'Parent blog does not exist';
            return $result;
        }

        // Lấy accounting records của shop con cho ngày này
        $sourceRecords = $this->accountingRepo->findByBlogAndDate($sourceBlogId, $date);

        if (empty($sourceRecords)) {
            $result['message'] = 'No accounting data found for this date';
            return $result;
        }

        // Đảm bảo parent blog có bảng accounting_roll_up
        $this->blogContext->executeInBlog($parentBlogId, function() {
            TGS_Sync_Roll_Up_Database::create_accounting_roll_up_table(get_current_blog_id());
        });

        // Sync từng record lên parent shop
        $syncedCount = 0;
        foreach ($sourceRecords as $record) {
            $synced = $this->blogContext->executeInBlog($parentBlogId, function() use ($record) {
                // Lưu record vào parent với blog_id gốc (của shop con)
                return $this->accountingRepo->save([
                    'blog_id' => $record['blog_id'], // Giữ nguyên blog_id của shop con
                    'roll_up_date' => $record['roll_up_date'],
                    'total_income' => $record['total_income'],
                    'total_expense' => $record['total_expense'],
                    'meta' => $record['meta'],
                ]);
            });

            if ($synced) {
                $syncedCount++;
            }
        }

        $result['success'] = true;
        $result['synced_count'] = $syncedCount;
        $result['parent_blog_id'] = $parentBlogId;

        return $result;
    }

    /**
     * Sync accounting data for a date range
     *
     * @param int $sourceBlogId Shop con
     * @param string $fromDate From date
     * @param string $toDate To date
     * @return array Kết quả sync
     */
    public function syncDateRange(int $sourceBlogId, string $fromDate, string $toDate): array
    {
        $result = [
            'success' => false,
            'source_blog_id' => $sourceBlogId,
            'from_date' => $fromDate,
            'to_date' => $toDate,
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
            $result['message'] = 'Parent blog does not exist';
            return $result;
        }

        // Lấy accounting records của shop con cho range
        $sourceRecords = $this->accountingRepo->findByDateRange($sourceBlogId, $fromDate, $toDate);

        if (empty($sourceRecords)) {
            $result['message'] = 'No accounting data found for this date range';
            return $result;
        }

        // Đảm bảo parent blog có bảng accounting_roll_up
        $this->blogContext->executeInBlog($parentBlogId, function() {
            TGS_Sync_Roll_Up_Database::create_accounting_roll_up_table(get_current_blog_id());
        });

        // Sync từng record lên parent shop
        $syncedCount = 0;
        foreach ($sourceRecords as $record) {
            $synced = $this->blogContext->executeInBlog($parentBlogId, function() use ($record) {
                // Lưu record vào parent với blog_id gốc (của shop con)
                return $this->accountingRepo->save([
                    'blog_id' => $record['blog_id'], // Giữ nguyên blog_id của shop con
                    'roll_up_date' => $record['roll_up_date'],
                    'total_income' => $record['total_income'],
                    'total_expense' => $record['total_expense'],
                    'meta' => $record['meta'],
                ]);
            });

            if ($synced) {
                $syncedCount++;
            }
        }

        $result['success'] = true;
        $result['synced_count'] = $syncedCount;
        $result['parent_blog_id'] = $parentBlogId;

        return $result;
    }
}
