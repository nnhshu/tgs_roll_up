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
     * @var CalculateDailyAccounting
     */
    private $calculateAccounting;

    /**
     * @var SyncAccountingToParentShop
     */
    private $syncAccountingUseCase;

    /**
     * @var DataSourceInterface
     */
    private $dataSource;

    /**
     * Constructor - sử dụng dependency injection
     */
    public function __construct(
        CalculateDailyProductRollup $calculateUseCase,
        CalculateDailyInventory $calculateInventory,
        SyncToParentShop $syncUseCase,
        SyncInventoryToParentShop $syncInventoryUseCase,
        CalculateDailyOrder $calculateOrder,
        SyncOrderToParentShop $syncOrderUseCase,
        CalculateDailyAccounting $calculateAccounting,
        SyncAccountingToParentShop $syncAccountingUseCase,
        DataSourceInterface $dataSource
    ) {
        $this->calculateUseCase = $calculateUseCase;
        $this->calculateInventory = $calculateInventory;
        $this->syncUseCase = $syncUseCase;
        $this->syncInventoryUseCase = $syncInventoryUseCase;
        $this->calculateOrder = $calculateOrder;
        $this->syncOrderUseCase = $syncOrderUseCase;
        $this->calculateAccounting = $calculateAccounting;
        $this->syncAccountingUseCase = $syncAccountingUseCase;
        $this->dataSource = $dataSource;
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
        error_log("Handling manual sync AJAX request");
        check_ajax_referer('tgs_sync_roll_up_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Permission denied', 'tgs-sync-roll-up')]);
        }

        $blogId = get_current_blog_id();
        $date = isset($_POST['date']) ? sanitize_text_field($_POST['date']) : current_time('Y-m-d');

        try {
            // Fire sync started action
            do_action('tgs_sync_started', $blogId, $date);

            // BƯỚC 1: Lấy TẤT CẢ ledgers trong ngày với is_croned = 0 và local_ledger_status = 4 (CHỈ MỘT LẦN)
            $allLedgers = $this->dataSource->getLedgers($date, [], false);

            if (empty($allLedgers)) {
                wp_send_json_success([
                    'message' => __('No unprocessed ledgers found for this date.', 'tgs-sync-roll-up'),
                    'result' => [
                        'blog_id' => $blogId,
                        'date' => $date,
                        'ledgers_processed' => 0,
                    ],
                ]);
                return;
            }

            error_log("Manual sync: Found " . count($allLedgers) . " unprocessed ledgers for date {$date}");

            // BƯỚC 2: Phân loại ledgers theo type
            $ledgersByType = $this->groupLedgersByType($allLedgers);

            // BƯỚC 3: Tính toán roll-up cho từng loại
            $allLedgerIds = array_column($allLedgers, 'local_ledger_id');
            $productCount = 0;
            $inventoryCount = 0;
            $orderCount = 0;
            $accountingCount = 0;

            // 3.1. Calculate product roll-up (type 10, 11)
            if (!empty($ledgersByType[TGS_LEDGER_TYPE_SALES_ROLL_UP]) || !empty($ledgersByType[11])) {
                $productLedgers = array_merge(
                    $ledgersByType[TGS_LEDGER_TYPE_SALES_ROLL_UP] ?? [],
                    $ledgersByType[11] ?? []
                );
                $productResult = $this->calculateUseCase->executeWithLedgers($blogId, $date, $productLedgers);
                $productCount = count($productResult['saved_ids'] ?? []);
            }

            // 3.2. Calculate inventory (type 1, 2, 6)
            if (!empty($ledgersByType[TGS_LEDGER_TYPE_IMPORT_ROLL_UP]) ||
                !empty($ledgersByType[TGS_LEDGER_TYPE_EXPORT_ROLL_UP]) ||
                !empty($ledgersByType[TGS_LEDGER_TYPE_DAMAGE_ROLL_UP])) {
                $inventoryLedgers = array_merge(
                    $ledgersByType[TGS_LEDGER_TYPE_IMPORT_ROLL_UP] ?? [],
                    $ledgersByType[TGS_LEDGER_TYPE_EXPORT_ROLL_UP] ?? [],
                    $ledgersByType[TGS_LEDGER_TYPE_DAMAGE_ROLL_UP] ?? []
                );
                $inventoryResult = $this->calculateInventory->executeWithLedgers($blogId, $date, $inventoryLedgers);
                $inventoryCount = $inventoryResult['saved_count'] ?? 0;
            }

            // 3.3. Calculate orders (type 10)
            if (!empty($ledgersByType[TGS_LEDGER_TYPE_SALES_ROLL_UP])) {
                $orderResult = $this->calculateOrder->executeWithLedgers($blogId, $date, $ledgersByType[TGS_LEDGER_TYPE_SALES_ROLL_UP]);
                $orderCount = $orderResult['daily'] ?? 0;
            }

            // 3.4. Calculate accounting (type 7, 8)
            if (!empty($ledgersByType[TGS_LEDGER_TYPE_RECEIPT_ROLL_UP]) || !empty($ledgersByType[TGS_LEDGER_TYPE_PAYMENT_ROLL_UP])) {
                $accountingLedgers = array_merge(
                    $ledgersByType[TGS_LEDGER_TYPE_RECEIPT_ROLL_UP] ?? [],
                    $ledgersByType[TGS_LEDGER_TYPE_PAYMENT_ROLL_UP] ?? []
                );
                $accountingResult = $this->calculateAccounting->executeWithLedgers($blogId, $date, $accountingLedgers);
                $accountingCount = $accountingResult['saved_count'] ?? 0;
            }

            // BƯỚC 4: Đánh dấu TẤT CẢ ledgers đã xử lý
            if (!empty($allLedgerIds)) {
                error_log("Manual sync: About to mark " . count($allLedgerIds) . " ledgers as processed");
                $marked = $this->dataSource->markLedgersAsProcessed($allLedgerIds);
                error_log("Manual sync: Mark result = " . ($marked ? 'success' : 'failed'));
            }

            // BƯỚC 5: Sync to parent
            $syncResult = $this->syncUseCase->execute($blogId, $date);
            $syncInventoryResult = $this->syncInventoryUseCase->syncByDate($blogId, $date);
            $syncOrderResult = $this->syncOrderUseCase->syncByDate($blogId, $date);
            $syncAccountingResult = $this->syncAccountingUseCase->syncByDate($blogId, $date);

            $result = [
                'blog_id' => $blogId,
                'date' => $date,
                'ledgers_processed' => count($allLedgerIds),
                'product_saved_count' => $productCount,
                'inventory_saved_count' => $inventoryCount,
                'order_count' => $orderCount,
                'accounting_count' => $accountingCount,
                'sync_product_result' => $syncResult,
                'sync_inventory_result' => $syncInventoryResult,
                'sync_order_result' => $syncOrderResult,
                'sync_accounting_result' => $syncAccountingResult,
            ];

            // Fire completed action
            do_action('tgs_sync_completed', $result);

            wp_send_json_success([
                'message' => __('Sync completed successfully! Products, inventory, orders and accounting synced.', 'tgs-sync-roll-up'),
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
     * Group ledgers by type
     *
     * @param array $ledgers All ledgers
     * @return array Ledgers grouped by type
     */
    private function groupLedgersByType(array $ledgers): array
    {
        $grouped = [];
        foreach ($ledgers as $ledger) {
            $type = intval($ledger['local_ledger_type']);
            if (!isset($grouped[$type])) {
                $grouped[$type] = [];
            }
            $grouped[$type][] = $ledger;
        }
        return $grouped;
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
                    // BƯỚC 1: Lấy TẤT CẢ ledgers trong ngày (CHỈ MỘT LẦN)
                    // Không quan tâm is_croned vì rebuild có thể xử lý lại
                    $allLedgers = $this->dataSource->getLedgers($date, [], false);

                    if (!empty($allLedgers)) {
                        // BƯỚC 2: Group by type
                        $ledgersByType = $this->groupLedgersByType($allLedgers);

                        // BƯỚC 3: Calculate roll-ups với pre-fetched ledgers
                        $allLedgerIds = array_column($allLedgers, 'local_ledger_id');

                        // 3.1. Product roll-up (types 10 và 11)
                        if (!empty($ledgersByType[TGS_LEDGER_TYPE_SALES_ROLL_UP]) || !empty($ledgersByType[11])) {
                            $productLedgers = array_merge(
                                $ledgersByType[TGS_LEDGER_TYPE_SALES_ROLL_UP] ?? [],
                                $ledgersByType[11] ?? []
                            );
                            $this->calculateUseCase->executeWithLedgers($blogId, $date, $productLedgers);
                        }

                        // 3.2. Inventory roll-up (types 1, 2, 6)
                        if (!empty($ledgersByType[TGS_LEDGER_TYPE_IMPORT_ROLL_UP]) || !empty($ledgersByType[TGS_LEDGER_TYPE_EXPORT_ROLL_UP]) || !empty($ledgersByType[TGS_LEDGER_TYPE_DAMAGE_ROLL_UP])) {
                            $inventoryLedgers = array_merge(
                                $ledgersByType[TGS_LEDGER_TYPE_IMPORT_ROLL_UP] ?? [],
                                $ledgersByType[TGS_LEDGER_TYPE_EXPORT_ROLL_UP] ?? [],
                                $ledgersByType[TGS_LEDGER_TYPE_DAMAGE_ROLL_UP] ?? []
                            );
                            $this->calculateInventory->executeWithLedgers($blogId, $date, $inventoryLedgers);
                        }

                        // 3.3. Order roll-up (type 10)
                        if (!empty($ledgersByType[TGS_LEDGER_TYPE_SALES_ROLL_UP])) {
                            $this->calculateOrder->executeWithLedgers($blogId, $date, $ledgersByType[TGS_LEDGER_TYPE_SALES_ROLL_UP]);
                        }

                        // 3.4. Accounting roll-up (types 7 và 8)
                        if (!empty($ledgersByType[TGS_LEDGER_TYPE_RECEIPT_ROLL_UP]) || !empty($ledgersByType[TGS_LEDGER_TYPE_PAYMENT_ROLL_UP])) {
                            $accountingLedgers = array_merge(
                                $ledgersByType[TGS_LEDGER_TYPE_RECEIPT_ROLL_UP] ?? [],
                                $ledgersByType[TGS_LEDGER_TYPE_PAYMENT_ROLL_UP] ?? []
                            );
                            $this->calculateAccounting->executeWithLedgers($blogId, $date, $accountingLedgers);
                        }

                        // BƯỚC 4: Mark ledgers as processed
                        if (!empty($allLedgerIds)) {
                            $this->dataSource->markLedgersAsProcessed($allLedgerIds);
                        }
                    }

                    // BƯỚC 5: Sync to parent if requested
                    if ($syncToParents) {
                        $this->syncUseCase->execute($blogId, $date);
                        $this->syncInventoryUseCase->syncByDate($blogId, $date);
                        $this->syncOrderUseCase->syncByDate($blogId, $date);
                        $this->syncAccountingUseCase->syncByDate($blogId, $date);
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
                    __('Rebuild completed! %d/%d days processed (products, inventory, orders & accounting).', 'tgs-sync-roll-up'),
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
