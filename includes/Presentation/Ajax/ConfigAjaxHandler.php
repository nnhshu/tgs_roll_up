<?php
/**
 * Config AJAX Handler
 * Xử lý các AJAX requests liên quan đến cấu hình parent-child shop
 *
 * @package TGS_Sync_Roll_Up
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class ConfigAjaxHandler
{
    /**
     * @var ConfigRepositoryInterface
     */
    private $configRepo;

    /**
     * Constructor - sử dụng dependency injection
     */
    public function __construct(ConfigRepositoryInterface $configRepo)
    {
        $this->configRepo = $configRepo;
    }

    /**
     * Register AJAX hooks
     */
    public function registerHooks(): void
    {
        // Parent shop management
        add_action('wp_ajax_tgs_save_parent_shop', [$this, 'handleSaveParentShop']);
        add_action('wp_ajax_tgs_cancel_parent_request', [$this, 'handleCancelParentRequest']);
        add_action('wp_ajax_tgs_approve_parent_request', [$this, 'handleApproveParentRequest']);
        add_action('wp_ajax_tgs_reject_parent_request', [$this, 'handleRejectParentRequest']);

        // Sync settings
        add_action('wp_ajax_tgs_save_sync_settings', [$this, 'handleSaveSyncSettings']);
        add_action('wp_ajax_tgs_get_sync_status', [$this, 'handleGetSyncStatus']);
    }

    /**
     * Handle save parent shop (gửi yêu cầu với status pending)
     */
    public function handleSaveParentShop(): void
    {
        check_ajax_referer('tgs_sync_roll_up_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'tgs-sync-roll-up')]);
        }

        $blogId = get_current_blog_id();
        $parentBlogId = isset($_POST['parent_blog_id']) ? intval($_POST['parent_blog_id']) : 0;

        // Validate
        if (empty($parentBlogId)) {
            wp_send_json_error(['message' => __('Please select a parent shop!', 'tgs-sync-roll-up')]);
        }

        if ($parentBlogId == $blogId) {
            wp_send_json_error(['message' => __('Cannot select yourself as parent shop!', 'tgs-sync-roll-up')]);
        }

        // Check if already configured and approved
        $currentConfig = $this->configRepo->getConfig($blogId);
        if ($currentConfig &&
            !empty($currentConfig->parent_blog_id) &&
            $currentConfig->approval_status === 'approved') {
            wp_send_json_error(['message' => __('Parent shop already configured and cannot be changed!', 'tgs-sync-roll-up')]);
        }

        try {
            // Save with pending status
            $result = $this->configRepo->saveConfig($blogId, [
                'parent_blog_id' => $parentBlogId,
                'approval_status' => 'pending',
            ]);

            if ($result) {
                wp_send_json_success([
                    'message' => __('Request sent! Waiting for parent shop confirmation.', 'tgs-sync-roll-up'),
                ]);
            } else {
                wp_send_json_error(['message' => __('Failed to send request', 'tgs-sync-roll-up')]);
            }
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Handle cancel parent request
     */
    public function handleCancelParentRequest(): void
    {
        check_ajax_referer('tgs_sync_roll_up_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'tgs-sync-roll-up')]);
        }

        $blogId = get_current_blog_id();
        $currentConfig = $this->configRepo->getConfig($blogId);

        // Validate
        if (!$currentConfig ||
            empty($currentConfig->approval_status) ||
            $currentConfig->approval_status !== 'pending') {
            wp_send_json_error(['message' => __('No pending request to cancel!', 'tgs-sync-roll-up')]);
        }

        try {
            // Clear parent_blog_id and approval_status
            $result = $this->configRepo->saveConfig($blogId, [
                'parent_blog_id' => null,
                'approval_status' => null,
            ]);

            if ($result !== false) {
                wp_send_json_success([
                    'message' => __('Request cancelled successfully!', 'tgs-sync-roll-up'),
                ]);
            } else {
                wp_send_json_error(['message' => __('Failed to cancel request', 'tgs-sync-roll-up')]);
            }
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Handle approve parent request (shop cha approve yêu cầu)
     */
    public function handleApproveParentRequest(): void
    {
        check_ajax_referer('tgs_sync_roll_up_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'tgs-sync-roll-up')]);
        }

        $childBlogId = isset($_POST['child_blog_id']) ? intval($_POST['child_blog_id']) : 0;
        $parentBlogId = get_current_blog_id();

        if (!$childBlogId) {
            wp_send_json_error(['message' => __('Invalid child shop ID!', 'tgs-sync-roll-up')]);
        }

        try {
            // Get child config
            $childConfig = $this->configRepo->getConfig($childBlogId);

            // Validate
            if (!$childConfig ||
                empty($childConfig->approval_status) ||
                $childConfig->approval_status !== 'pending' ||
                $childConfig->parent_blog_id != $parentBlogId) {
                wp_send_json_error(['message' => __('Invalid or expired request!', 'tgs-sync-roll-up')]);
            }

            // Update to approved
            $result = $this->configRepo->updateApprovalStatus($childBlogId, 'approved');

            if ($result) {
                do_action('tgs_parent_request_approved', $childBlogId, $parentBlogId);

                wp_send_json_success([
                    'message' => __('Request approved successfully!', 'tgs-sync-roll-up'),
                ]);
            } else {
                wp_send_json_error(['message' => __('Failed to approve request', 'tgs-sync-roll-up')]);
            }
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Handle reject parent request
     */
    public function handleRejectParentRequest(): void
    {
        check_ajax_referer('tgs_sync_roll_up_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'tgs-sync-roll-up')]);
        }

        $childBlogId = isset($_POST['child_blog_id']) ? intval($_POST['child_blog_id']) : 0;
        $parentBlogId = get_current_blog_id();

        if (!$childBlogId) {
            wp_send_json_error(['message' => __('Invalid child shop ID!', 'tgs-sync-roll-up')]);
        }

        try {
            // Get child config
            $childConfig = $this->configRepo->getConfig($childBlogId);

            // Validate
            if (!$childConfig ||
                empty($childConfig->approval_status) ||
                $childConfig->approval_status !== 'pending' ||
                $childConfig->parent_blog_id != $parentBlogId) {
                wp_send_json_error(['message' => __('Invalid or expired request!', 'tgs-sync-roll-up')]);
            }

            // Clear parent and set rejected
            $result = $this->configRepo->saveConfig($childBlogId, [
                'parent_blog_id' => null,
                'approval_status' => 'rejected',
            ]);

            if ($result !== false) {
                do_action('tgs_parent_request_rejected', $childBlogId, $parentBlogId);

                wp_send_json_success([
                    'message' => __('Request rejected!', 'tgs-sync-roll-up'),
                ]);
            } else {
                wp_send_json_error(['message' => __('Failed to reject request', 'tgs-sync-roll-up')]);
            }
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Handle save sync settings
     */
    public function handleSaveSyncSettings(): void
    {
        check_ajax_referer('tgs_sync_roll_up_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'tgs-sync-roll-up')]);
        }

        $blogId = get_current_blog_id();
        $syncEnabled = isset($_POST['sync_enabled']) ? intval($_POST['sync_enabled']) : 0;
        $syncFrequency = isset($_POST['sync_frequency']) ? sanitize_text_field($_POST['sync_frequency']) : 'hourly';

        try {
            $result = $this->configRepo->saveConfig($blogId, [
                'sync_enabled' => $syncEnabled,
                'sync_interval' => $syncFrequency,
            ]);

            if ($result !== false) {
                // Fire action for cron update
                do_action('tgs_sync_settings_updated', $blogId, $syncEnabled, $syncFrequency);

                wp_send_json_success([
                    'message' => __('Settings saved successfully!', 'tgs-sync-roll-up'),
                ]);
            } else {
                wp_send_json_error(['message' => __('Failed to save settings', 'tgs-sync-roll-up')]);
            }
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Handle get sync status
     */
    public function handleGetSyncStatus(): void
    {
        check_ajax_referer('tgs_sync_roll_up_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'tgs-sync-roll-up')]);
        }

        $blogId = get_current_blog_id();

        try {
            $status = $this->configRepo->getSyncStatus($blogId);

            // Get cron info
            $nextScheduled = wp_next_scheduled('tgs_sync_rollup_cron');
            $cronInfo = [
                'next_scheduled' => $nextScheduled ? date('Y-m-d H:i:s', $nextScheduled) : null,
                'frequency' => $status['sync_interval'] ?? 'hourly',
            ];

            wp_send_json_success([
                'status' => $status,
                'cron' => $cronInfo,
            ]);
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }
}
