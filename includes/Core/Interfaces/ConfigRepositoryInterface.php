<?php
/**
 * Config Repository Interface
 * Abstraction cho sync configuration persistence
 *
 * @package TGS_Sync_Roll_Up
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

interface ConfigRepositoryInterface
{
    /**
     * Lấy config của một blog
     *
     * @param int $blogId Blog ID
     * @return object|null Config object hoặc null
     */
    public function getConfig(int $blogId): ?object;

    /**
     * Lưu config
     *
     * @param int $blogId Blog ID
     * @param array $data Config data
     * @return bool Success or failure
     */
    public function saveConfig(int $blogId, array $data): bool;

    /**
     * Lấy parent blog ID của một shop
     *
     * @param int $blogId Blog ID
     * @return int|null Parent blog ID hoặc null
     */
    public function getParentBlogId(int $blogId): ?int;

    /**
     * Lấy danh sách child blogs của parent
     *
     * @param int $parentBlogId Parent blog ID
     * @param string $approvalStatus Filter theo approval status (optional)
     * @return array Mảng child blog IDs
     */
    public function getChildBlogs(int $parentBlogId, string $approvalStatus = 'approved'): array;

    /**
     * Cập nhật approval status
     *
     * @param int $blogId Blog ID
     * @param string $status Status: pending, approved, rejected
     * @return bool Success or failure
     */
    public function updateApprovalStatus(int $blogId, string $status): bool;

    /**
     * Lấy sync status
     *
     * @param int $blogId Blog ID
     * @return array Sync status info
     */
    public function getSyncStatus(int $blogId): array;
}
