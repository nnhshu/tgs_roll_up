<?php
/**
 * Calculate Daily Roll-Up Use Case
 * Business logic cho việc tính toán roll-up hàng ngày
 *
 * @package TGS_Sync_Roll_Up
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class CalculateDailyRollUp
{
    /**
     * @var DataSourceInterface
     */
    private $dataSource;

    /**
     * @var RollUpRepositoryInterface
     */
    private $rollUpRepo;

    /**
     * @var BlogContext
     */
    private $blogContext;

    /**
     * Constructor
     *
     * @param DataSourceInterface $dataSource
     * @param RollUpRepositoryInterface $rollUpRepo
     * @param BlogContext $blogContext
     */
    public function __construct(
        DataSourceInterface $dataSource,
        RollUpRepositoryInterface $rollUpRepo,
        BlogContext $blogContext
    ) {
        $this->dataSource = $dataSource;
        $this->rollUpRepo = $rollUpRepo;
        $this->blogContext = $blogContext;
    }

    /**
     * Execute calculation
     *
     * @param int $blogId Blog ID
     * @param string $date Ngày (Y-m-d)
     * @param int|null $syncType Loại sync (null = all)
     * @return array Mảng roll-up records đã tạo
     */
    public function execute(int $blogId, string $date, ?int $syncType = null): array
    {
        return $this->blogContext->executeInBlog($blogId, function() use ($blogId, $date, $syncType) {
            // Kiểm tra data source có available không
            if (!$this->dataSource->isAvailable()) {
                throw new Exception('Data source is not available');
            }

            // Xác định các loại ledger cần xử lý
            $types = $this->getTypesToProcess($syncType);

            // Lấy ledgers
            $ledgers = $this->dataSource->getLedgers($date, $types, true);

            if (empty($ledgers)) {
                return [];
            }

            // Lấy ledger items
            $ledger_ids = array_column($ledgers, 'id');
            $items = $this->dataSource->getLedgerItems($ledger_ids);

            // Group items theo ledger_id
            $items_by_ledger = [];
            foreach ($items as $item) {
                $ledger_id = $item['local_ledger_id'];
                if (!isset($items_by_ledger[$ledger_id])) {
                    $items_by_ledger[$ledger_id] = [];
                }
                $items_by_ledger[$ledger_id][] = $item;
            }

            // Tính roll-up cho từng sản phẩm
            $roll_up_data = [];

            foreach ($ledgers as $ledger) {
                $ledger_id = $ledger['id'];
                $ledger_type = intval($ledger['type']);

                if (!isset($items_by_ledger[$ledger_id])) {
                    continue;
                }

                foreach ($items_by_ledger[$ledger_id] as $item) {
                    $product_id = $item['local_product_name_id'];
                    $key = $product_id . '_' . $ledger_type;

                    if (!isset($roll_up_data[$key])) {
                        $roll_up_data[$key] = [
                            'blog_id' => $blogId,
                            'roll_up_date' => $date,
                            'local_product_name_id' => $product_id,
                            'global_product_name_id' => $item['global_product_name_id'] ?? null,
                            'type' => $ledger_type,
                            'amount_after_tax' => 0,
                            'tax' => 0,
                            'quantity' => 0,
                            'lot_ids' => [],
                        ];
                    }

                    $roll_up_data[$key]['amount_after_tax'] += floatval($item['amount_after_tax'] ?? 0);
                    $roll_up_data[$key]['tax'] += floatval($item['tax'] ?? 0);
                    $roll_up_data[$key]['quantity'] += floatval($item['quantity'] ?? 0);

                    // Lưu lot_ids
                    if (!empty($item['list_product_lots'])) {
                        $lots = json_decode($item['list_product_lots'], true);
                        if (is_array($lots)) {
                            foreach ($lots as $lot) {
                                if (!empty($lot['id'])) {
                                    $roll_up_data[$key]['lot_ids'][] = intval($lot['id']);
                                }
                            }
                        }
                    }
                }
            }

            // Lưu vào database
            $saved_ids = [];
            foreach ($roll_up_data as $data) {
                // Unique lot_ids
                $data['lot_ids'] = array_unique($data['lot_ids']);

                $roll_up_id = $this->rollUpRepo->save($data, false); // Không overwrite, merge data
                if ($roll_up_id) {
                    $saved_ids[] = $roll_up_id;
                }
            }

            // Đánh dấu ledgers đã xử lý
            $this->dataSource->markLedgersAsProcessed($ledger_ids);

            return $saved_ids;
        });
    }

    /**
     * Lấy danh sách types cần xử lý
     *
     * @param int|null $syncType
     * @return array
     */
    private function getTypesToProcess(?int $syncType): array
    {
        if ($syncType !== null) {
            return [$syncType];
        }

        // Tất cả types
        return [
            TGS_LEDGER_TYPE_IMPORT,
            TGS_LEDGER_TYPE_EXPORT,
            TGS_LEDGER_TYPE_DAMAGE,
            TGS_LEDGER_TYPE_PURCHASE,
            TGS_LEDGER_TYPE_SALES,
            TGS_LEDGER_TYPE_RETURN,
        ];
    }
}
