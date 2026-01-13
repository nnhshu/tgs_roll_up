<?php
/**
 * Blog Context Wrapper
 * Wrapper cho multisite switch_to_blog/restore_current_blog operations
 *
 * @package TGS_Sync_Roll_Up
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class BlogContext
{
    /**
     * Execute callback trong context của một blog
     *
     * @param int $blogId Blog ID
     * @param callable $callback Callback function
     * @return mixed Kết quả của callback
     * @throws Exception Nếu callback fails
     */
    public function executeInBlog(int $blogId, callable $callback)
    {
        $originalBlog = get_current_blog_id();
        $switched = false;

        // Chỉ switch nếu khác blog hiện tại
        if ($blogId !== $originalBlog) {
            switch_to_blog($blogId);
            $switched = true;
        }

        try {
            $result = $callback();
            return $result;
        } catch (Exception $e) {
            // Đảm bảo restore ngay cả khi có exception
            if ($switched) {
                restore_current_blog();
            }
            throw $e;
        } finally {
            // Restore về blog gốc
            if ($switched) {
                restore_current_blog();
            }
        }
    }

    /**
     * Execute callback cho multiple blogs
     *
     * @param array $blogIds Mảng blog IDs
     * @param callable $callback Callback nhận (blogId) làm tham số
     * @return array Mảng kết quả theo blog_id => result
     */
    public function executeInMultipleBlogs(array $blogIds, callable $callback): array
    {
        $results = [];

        foreach ($blogIds as $blogId) {
            try {
                $results[$blogId] = $this->executeInBlog($blogId, function() use ($callback, $blogId) {
                    return $callback($blogId);
                });
            } catch (Exception $e) {
                $results[$blogId] = [
                    'error' => $e->getMessage(),
                    'success' => false,
                ];
            }
        }

        return $results;
    }

    /**
     * Lấy blog name
     *
     * @param int $blogId Blog ID
     * @return string Blog name
     */
    public function getBlogName(int $blogId): string
    {
        if (!is_multisite()) {
            return get_bloginfo('name');
        }

        return $this->executeInBlog($blogId, function() {
            return get_bloginfo('name');
        });
    }

    /**
     * Kiểm tra blog có tồn tại không
     *
     * @param int $blogId Blog ID
     * @return bool
     */
    public function blogExists(int $blogId): bool
    {
        global $wpdb;

        if (!is_multisite()) {
            return $blogId === 1;
        }

        $blog = $wpdb->get_var($wpdb->prepare(
            "SELECT blog_id FROM {$wpdb->blogs} WHERE blog_id = %d AND deleted = 0",
            $blogId
        ));

        return !empty($blog);
    }

    /**
     * Lấy danh sách tất cả blogs
     *
     * @return array Mảng blog objects
     */
    public function getAllBlogs(): array
    {
        if (!is_multisite()) {
            return [
                (object) [
                    'blog_id' => 1,
                    'domain' => get_site_url(),
                    'path' => '/',
                ]
            ];
        }

        return get_sites([
            'number' => 1000,
            'orderby' => 'id',
            'order' => 'ASC',
        ]);
    }
}
