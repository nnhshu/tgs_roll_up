<?php
/**
 * Sync Type Registry
 * Registry pattern cho các loại sync có thể mở rộng
 *
 * @package TGS_Sync_Roll_Up
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SyncTypeRegistry
{
    /**
     * @var array Registered sync handlers
     */
    private static $handlers = [];

    /**
     * @var array Sync type metadata
     */
    private static $metadata = [];

    /**
     * Đăng ký một sync type handler
     *
     * @param string $type Tên sync type (ví dụ: 'products', 'inventory', 'custom_metric')
     * @param callable $handler Callback function để xử lý sync
     * @param array $meta Metadata về sync type (label, description, icon, etc.)
     */
    public static function register(string $type, callable $handler, array $meta = []): void
    {
        self::$handlers[$type] = $handler;
        self::$metadata[$type] = array_merge([
            'label' => ucfirst($type),
            'description' => '',
            'icon' => 'dashicons-update',
            'priority' => 10,
        ], $meta);
    }

    /**
     * Lấy handler của một sync type
     *
     * @param string $type Sync type
     * @return callable|null Handler hoặc null
     */
    public static function get(string $type): ?callable
    {
        return self::$handlers[$type] ?? null;
    }

    /**
     * Lấy tất cả registered handlers
     *
     * @return array Map type => handler
     */
    public static function getAll(): array
    {
        return self::$handlers;
    }

    /**
     * Lấy metadata của một sync type
     *
     * @param string $type Sync type
     * @return array|null Metadata hoặc null
     */
    public static function getMetadata(string $type): ?array
    {
        return self::$metadata[$type] ?? null;
    }

    /**
     * Lấy tất cả sync types với metadata
     *
     * @return array Map type => metadata
     */
    public static function getAllMetadata(): array
    {
        return self::$metadata;
    }

    /**
     * Kiểm tra sync type có tồn tại không
     *
     * @param string $type Sync type
     * @return bool
     */
    public static function has(string $type): bool
    {
        return isset(self::$handlers[$type]);
    }

    /**
     * Xóa một sync type
     *
     * @param string $type Sync type
     */
    public static function unregister(string $type): void
    {
        unset(self::$handlers[$type]);
        unset(self::$metadata[$type]);
    }

    /**
     * Execute sync cho một type cụ thể
     *
     * @param string $type Sync type
     * @param int $blogId Blog ID
     * @param string $date Date
     * @param array $args Additional arguments
     * @return mixed Kết quả từ handler
     * @throws Exception Nếu type không tồn tại
     */
    public static function execute(string $type, int $blogId, string $date, array $args = [])
    {
        $handler = self::get($type);

        if (!$handler) {
            throw new Exception("Sync type '{$type}' is not registered");
        }

        // Allow filters để modify args trước khi execute
        $args = apply_filters('tgs_sync_before_execute', $args, $type, $blogId, $date);

        $result = call_user_func($handler, $blogId, $date, $args);

        // Allow actions sau khi execute
        do_action('tgs_sync_after_execute', $result, $type, $blogId, $date);

        return $result;
    }

    /**
     * Get sync types sorted by priority
     *
     * @return array Sorted array of types
     */
    public static function getSortedTypes(): array
    {
        $types = self::$metadata;

        uasort($types, function($a, $b) {
            return ($a['priority'] ?? 10) <=> ($b['priority'] ?? 10);
        });

        return array_keys($types);
    }
}
