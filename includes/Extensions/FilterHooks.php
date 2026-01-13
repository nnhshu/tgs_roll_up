<?php
/**
 * Filter Hooks
 * WordPress filters và actions cho extensibility
 *
 * @package TGS_Sync_Roll_Up
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class FilterHooks
{
    /**
     * Initialize hooks
     */
    public static function init(): void
    {
        // Filters
        add_filter('tgs_sync_before_calculate', [__CLASS__, 'beforeCalculate'], 10, 3);
        add_filter('tgs_sync_after_calculate', [__CLASS__, 'afterCalculate'], 10, 2);
        add_filter('tgs_sync_modify_data', [__CLASS__, 'modifyData'], 10, 2);
        add_filter('tgs_sync_custom_types', [__CLASS__, 'customTypes'], 10, 1);

        // Actions
        add_action('tgs_sync_completed', [__CLASS__, 'onSyncCompleted'], 10, 2);
        add_action('tgs_sync_failed', [__CLASS__, 'onSyncFailed'], 10, 3);
        add_action('tgs_sync_started', [__CLASS__, 'onSyncStarted'], 10, 2);
    }

    /**
     * Filter: Before calculate
     * Cho phép modify dữ liệu trước khi tính toán
     *
     * @param array $data Data sẽ được tính
     * @param int $blogId Blog ID
     * @param string $date Date
     * @return array Modified data
     */
    public static function beforeCalculate(array $data, int $blogId, string $date): array
    {
        return $data;
    }

    /**
     * Filter: After calculate
     * Cho phép modify kết quả sau khi tính toán
     *
     * @param array $result Kết quả tính toán
     * @param array $context Context information
     * @return array Modified result
     */
    public static function afterCalculate(array $result, array $context): array
    {
        return $result;
    }

    /**
     * Filter: Modify data
     * Cho phép modify roll-up data trước khi lưu
     *
     * @param array $data Roll-up data
     * @param string $type Sync type
     * @return array Modified data
     */
    public static function modifyData(array $data, string $type): array
    {
        return $data;
    }

    /**
     * Filter: Custom types
     * Cho phép thêm custom sync types
     *
     * @param array $types Danh sách types mặc định
     * @return array Modified types
     */
    public static function customTypes(array $types): array
    {
        return $types;
    }

    /**
     * Action: Sync completed
     *
     * @param array $result Kết quả sync
     * @param array $context Context
     */
    public static function onSyncCompleted(array $result, array $context): void
    {
        // Hook point cho third-party plugins
        // Ví dụ: Gửi notification, log analytics, trigger webhooks, etc.
    }

    /**
     * Action: Sync failed
     *
     * @param string $error Error message
     * @param int $blogId Blog ID
     * @param string $date Date
     */
    public static function onSyncFailed(string $error, int $blogId, string $date): void
    {
        // Hook point cho error handling
        // Ví dụ: Alert admins, log errors, retry logic, etc.
    }

    /**
     * Action: Sync started
     *
     * @param int $blogId Blog ID
     * @param string $date Date
     */
    public static function onSyncStarted(int $blogId, string $date): void
    {
        // Hook point cho pre-sync tasks
        // Ví dụ: Update status, prepare cache, etc.
    }
}

/**
 * Helper functions cho third-party developers
 */

/**
 * Đăng ký một custom sync type
 *
 * @param string $type Type name
 * @param callable $handler Handler function
 * @param array $meta Metadata
 */
function tgs_register_sync_type(string $type, callable $handler, array $meta = []): void
{
    SyncTypeRegistry::register($type, $handler, $meta);
}

/**
 * Execute một sync type
 *
 * @param string $type Type name
 * @param int $blogId Blog ID
 * @param string $date Date
 * @param array $args Arguments
 * @return mixed Result
 */
function tgs_execute_sync(string $type, int $blogId, string $date, array $args = [])
{
    return SyncTypeRegistry::execute($type, $blogId, $date, $args);
}

/**
 * Lấy danh sách available sync types
 *
 * @return array Types với metadata
 */
function tgs_get_sync_types(): array
{
    return SyncTypeRegistry::getAllMetadata();
}
