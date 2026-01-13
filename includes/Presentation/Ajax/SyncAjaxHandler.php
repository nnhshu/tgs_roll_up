<?php
/**
 * Sync AJAX Handler
 * Xử lý các AJAX requests liên quan đến sync
 *
 * @package TGS_Sync_Roll_Up
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class SyncAjaxHandler
{
    /**
     * @var CalculateDailyProductRollup
     */
    private $calculateUseCase;

    /**
     * @var SyncToParentShop
     */
    private $syncUseCase;

    /**
     * Constructor - sử dụng dependency injection
     */
    public function __construct(
        CalculateDailyProductRollup $calculateUseCase,
        SyncToParentShop $syncUseCase
    ) {
        $this->calculateUseCase = $calculateUseCase;
        $this->syncUseCase = $syncUseCase;
    }

    /**
     * Register AJAX hooks
     */
    public function registerHooks(): void
    {
        add_action('wp_ajax_tgs_manual_sync', [$this, 'handleManualSync']);
        add_action('wp_ajax_tgs_rebuild_rollup', [$this, 'handleRebuild']);
    }

    /**
     * Handle manual sync
     */
    public function handleManualSync(): void
    {
        check_ajax_referer('tgs_sync_roll_up_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'tgs-sync-roll-up')]);
        }

        $blogId = get_current_blog_id();
        $date = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : current_time('Y-m-d');
        $syncType = isset($_POST['sync_type']) ? sanitize_text_field($_POST['sync_type']) : 'all';

        try {
            // Fire sync started action
            do_action('tgs_sync_started', $blogId, $date);

            // Calculate roll-up
            $type = ($syncType === 'all') ? null : intval($syncType);
            $savedIds = $this->calculateUseCase->execute($blogId, $date, $type);

            // Sync to parent
            $syncResult = $this->syncUseCase->execute($blogId, $date);

            $result = [
                'blog_id' => $blogId,
                'date' => $date,
                'saved_count' => count($savedIds),
                'sync_result' => $syncResult,
            ];

            // Fire completed action
            do_action('tgs_sync_completed', $result, ['type' => $syncType]);

            wp_send_json_success([
                'message' => __('Sync completed successfully!', 'tgs-sync-roll-up'),
                'result' => $result,
            ]);

        } catch (Exception $e) {
            // Fire failed action
            do_action('tgs_sync_failed', $e->getMessage(), $blogId, $date);

            wp_send_json_error([
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle rebuild
     */
    public function handleRebuild(): void
    {
        check_ajax_referer('tgs_sync_roll_up_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'tgs-sync-roll-up')]);
        }

        $blogId = get_current_blog_id();
        $startDate = isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : date('Y-m-01');
        $endDate = isset($_POST['end_date']) ? sanitize_text_field($_POST['end_date']) : current_time('Y-m-d');
        $syncToParents = isset($_POST['sync_to_parents']) ? (bool) $_POST['sync_to_parents'] : true;

        try {
            $results = [
                'total' => 0,
                'success' => 0,
                'failed' => 0,
                'details' => [],
            ];

            $current = strtotime($startDate);
            $end = strtotime($endDate);

            while ($current <= $end) {
                $date = date('Y-m-d', $current);
                $results['total']++;

                try {
                    // Calculate
                    $this->calculateUseCase->execute($blogId, $date, null);

                    // Sync nếu được yêu cầu
                    if ($syncToParents) {
                        $this->syncUseCase->execute($blogId, $date);
                    }

                    $results['success']++;
                } catch (Exception $e) {
                    $results['failed']++;
                    $results['details'][$date] = $e->getMessage();
                }

                $current = strtotime('+1 day', $current);
            }

            wp_send_json_success([
                'message' => sprintf(
                    __('Rebuild completed! %d/%d days processed.', 'tgs-sync-roll-up'),
                    $results['success'],
                    $results['total']
                ),
                'result' => $results,
            ]);

        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }
}
