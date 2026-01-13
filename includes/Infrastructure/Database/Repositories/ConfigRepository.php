<?php
/**
 * Config Repository
 * Implementation của ConfigRepositoryInterface
 *
 * @package TGS_Sync_Roll_Up
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

require_once TGS_SYNC_ROLL_UP_PATH . 'includes/Core/Interfaces/ConfigRepositoryInterface.php';

class ConfigRepository implements ConfigRepositoryInterface
{
    /**
     * @var wpdb
     */
    private $wpdb;

    /**
     * Constructor
     */
    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
    }

    /**
     * {@inheritdoc}
     */
    public function getConfig(int $blogId): ?object
    {
        if (!defined('TGSR_TABLE_SYNC_ROLL_UP_CONFIG')) {
            return null;
        }

        $table = TGSR_TABLE_SYNC_ROLL_UP_CONFIG;

        return $this->wpdb->get_row($this->wpdb->prepare(
            "SELECT * FROM {$table} WHERE blog_id = %d",
            $blogId
        ));
    }

    /**
     * {@inheritdoc}
     */
    public function saveConfig(int $blogId, array $data): bool
    {
        if (!defined('TGSR_TABLE_SYNC_ROLL_UP_CONFIG')) {
            return false;
        }

        $table = TGSR_TABLE_SYNC_ROLL_UP_CONFIG;

        // Check if config exists
        $existing = $this->getConfig($blogId);

        $config_data = [
            'blog_id' => $blogId,
            'updated_at' => current_time('mysql'),
        ];

        // Merge với data mới
        if (isset($data['parent_blog_id'])) {
            $config_data['parent_blog_id'] = $data['parent_blog_id'];
        }
        if (isset($data['approval_status'])) {
            $config_data['approval_status'] = $data['approval_status'];
        }
        if (isset($data['sync_interval'])) {
            $config_data['sync_interval'] = $data['sync_interval'];
        }
        if (isset($data['sync_enabled'])) {
            $config_data['sync_enabled'] = intval($data['sync_enabled']);
        }

        if ($existing) {
            // Update
            $result = $this->wpdb->update(
                $table,
                $config_data,
                ['blog_id' => $blogId],
                '%s',
                '%d'
            );
        } else {
            // Insert
            $config_data['created_at'] = current_time('mysql');
            $result = $this->wpdb->insert($table, $config_data, '%s');
        }

        return $result !== false;
    }

    /**
     * {@inheritdoc}
     */
    public function getParentBlogId(int $blogId): ?int
    {
        $config = $this->getConfig($blogId);

        if (!$config || empty($config->parent_blog_id)) {
            return null;
        }

        return intval($config->parent_blog_id);
    }

    /**
     * {@inheritdoc}
     */
    public function getChildBlogs(int $parentBlogId, string $approvalStatus = 'approved'): array
    {
        if (!defined('TGSR_TABLE_SYNC_ROLL_UP_CONFIG')) {
            return [];
        }

        $table = TGSR_TABLE_SYNC_ROLL_UP_CONFIG;

        $results = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT blog_id FROM {$table}
             WHERE parent_blog_id = %d
             AND approval_status = %s",
            $parentBlogId,
            $approvalStatus
        ));

        return array_map(function($row) {
            return intval($row->blog_id);
        }, $results);
    }

    /**
     * {@inheritdoc}
     */
    public function updateApprovalStatus(int $blogId, string $status): bool
    {
        return $this->saveConfig($blogId, [
            'approval_status' => $status,
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function getSyncStatus(int $blogId): array
    {
        $config = $this->getConfig($blogId);

        if (!$config) {
            return [
                'enabled' => false,
                'last_sync_at' => null,
                'next_sync_at' => null,
                'parent_blog_id' => null,
                'approval_status' => null,
            ];
        }

        return [
            'enabled' => !empty($config->sync_enabled),
            'last_sync_at' => $config->last_sync_at ?? null,
            'next_sync_at' => $config->next_sync_at ?? null,
            'parent_blog_id' => $config->parent_blog_id ?? null,
            'approval_status' => $config->approval_status ?? null,
            'sync_interval' => $config->sync_interval ?? 'hourly',
        ];
    }
}
