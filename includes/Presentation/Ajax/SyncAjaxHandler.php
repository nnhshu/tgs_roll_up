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
     * @var CalculateDailyInventory
     */
    private $calculateInventory;

    /**
     * @var SyncToParentShop
     */
    private $syncUseCase;

    /**
     * @var SyncInventoryToParentShop
     */
    private $syncInventoryUseCase;

    /**
     * @var CalculateDailyOrder
     */
    private $calculateOrder;

    /**
     * @var SyncOrderToParentShop
     */
    private $syncOrderUseCase;

    /**
     * Constructor - sử dụng dependency injection
     */
    public function __construct(
        CalculateDailyProductRollup $calculateUseCase,
        CalculateDailyInventory $calculateInventory,
        SyncToParentShop $syncUseCase,
        SyncInventoryToParentShop $syncInventoryUseCase,
        CalculateDailyOrder $calculateOrder,
        SyncOrderToParentShop $syncOrderUseCase
    ) {
        $this->calculateUseCase = $calculateUseCase;
        $this->calculateInventory = $calculateInventory;
        $this->syncUseCase = $syncUseCase;
        $this->syncInventoryUseCase = $syncInventoryUseCase;
        $this->calculateOrder = $calculateOrder;
        $this->syncOrderUseCase = $syncOrderUseCase;
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
        error_log("Handling manual 123sync AJAX request");
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

            // 1. Calculate product roll-up
            $type = ($syncType === 'all') ? null : intval($syncType);
            $savedIds = $this->calculateUseCase->execute($blogId, $date, $type);

            // 2. Calculate inventory roll-up
            $inventoryCount = $this->calculateInventory->execute($blogId, $date);

            // 3. Calculate order roll-up
            $orderResult = $this->calculateOrder->execute($blogId, $date);

            // 4. Sync product roll-up to parent
            $syncResult = $this->syncUseCase->execute($blogId, $date);

            // 5. Sync inventory to parent
            $syncInventoryResult = $this->syncInventoryUseCase->syncByDate($blogId, $date);

            // 6. Sync orders to parent
            $syncOrderResult = $this->syncOrderUseCase->syncByDate($blogId, $date);

            $result = [
                'blog_id' => $blogId,
                'date' => $date,
                'product_saved_count' => count($savedIds),
                'inventory_saved_count' => $inventoryCount,
                'order_count' => $orderResult['daily'],
                'sync_product_result' => $syncResult,
                'sync_inventory_result' => $syncInventoryResult,
                'sync_order_result' => $syncOrderResult,
            ];

            // Fire completed action
            do_action('tgs_sync_completed', $result, ['type' => $syncType]);

            wp_send_json_success([
                'message' => __('Sync completed successfully! Products, inventory, and orders synced.', 'tgs-sync-roll-up'),
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
                    // 1. Calculate product roll-up
                    $this->calculateUseCase->execute($blogId, $date, null);

                    // 2. Calculate inventory roll-up
                    $this->calculateInventory->execute($blogId, $date);

                    // 3. Calculate order roll-up
                    $this->calculateOrder->execute($blogId, $date);

                    // 4. Sync to parent if requested
                    if ($syncToParents) {
                        $this->syncUseCase->execute($blogId, $date);
                        $this->syncInventoryUseCase->syncByDate($blogId, $date);
                        $this->syncOrderUseCase->syncByDate($blogId, $date);
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
                    __('Rebuild completed! %d/%d days processed (products, inventory & orders).', 'tgs-sync-roll-up'),
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
