<?php
/**
 * Roll-Up Repository Interface
 * Abstraction cho roll-up data persistence
 *
 * @package TGS_Sync_Roll_Up
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

interface RollUpRepositoryInterface
{
    /**
     * Lưu roll-up data
     *
     * @param array $data Roll-up data
     * @param bool $overwrite Ghi đè data cũ hay merge
     * @return int Roll-up ID hoặc 0 nếu thất bại
     */
    public function save(array $data, bool $overwrite = false): int;

    /**
     * Lấy roll-up theo blog ID và date
     *
     * @param int $blogId Blog ID
     * @param string $date Ngày (Y-m-d)
     * @return array|null Roll-up data hoặc null
     */
    public function findByBlogAndDate(int $blogId, string $date): ?array;

    /**
     * Tính tổng revenue theo date range
     *
     * @param int $blogId Blog ID
     * @param string $fromDate Ngày bắt đầu (Y-m-d)
     * @param string $toDate Ngày kết thúc (Y-m-d)
     * @return array Aggregated data
     */
    public function sumByDateRange(int $blogId, string $fromDate, string $toDate): array;

    /**
     * Lấy multiple records theo date range
     *
     * @param int $blogId Blog ID
     * @param string $fromDate Ngày bắt đầu (Y-m-d)
     * @param string $toDate Ngày kết thúc (Y-m-d)
     * @return array Mảng roll-up records
     */
    public function findByDateRange(int $blogId, string $fromDate, string $toDate): array;

    /**
     * Xóa roll-up data theo date range
     *
     * @param int $blogId Blog ID
     * @param string $fromDate Ngày bắt đầu (Y-m-d)
     * @param string $toDate Ngày kết thúc (Y-m-d)
     * @return bool Success or failure
     */
    public function deleteByDateRange(int $blogId, string $fromDate, string $toDate): bool;

    /**
     * Lưu metadata cho roll-up
     *
     * @param int $rollUpId Roll-up ID
     * @param array $meta Metadata
     * @return bool Success or failure
     */
    public function saveMeta(int $rollUpId, array $meta): bool;

    /**
     * Lấy metadata của roll-up
     *
     * @param int $rollUpId Roll-up ID
     * @return array|null Metadata hoặc null
     */
    public function getMeta(int $rollUpId): ?array;
}
