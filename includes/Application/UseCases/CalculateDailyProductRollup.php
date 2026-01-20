<?php
/**
 * Calculate Daily Product Roll-Up Use Case
 * Business logic cho việc tính toán roll-up hàng ngày (sản phẩm)
 *
 * @package TGS_Sync_Roll_Up
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

class CalculateDailyProductRollup
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
     * Execute calculation (query ledgers from database)
     *
     * @param int $blogId Blog ID
     * @param string $date Ngày (Y-m-d)
     * @param int|null $syncType Loại sync (null = all)
     * @return array ['saved_ids' => array, 'ledger_ids' => array]
     */
    public function execute(int $blogId, string $date, ?int $syncType = null): array
    {
        return $this->blogContext->executeInBlog($blogId, function() use ($blogId, $date, $syncType) {
            if (!$this->dataSource->isAvailable()) {
                throw new Exception('Data source is not available');
            }

            $types = $this->getTypesToProcess($syncType);
            $ledgers = $this->dataSource->getLedgers($date, $types, true);

            if (empty($ledgers)) {
                return ['saved_ids' => [], 'ledger_ids' => []];
            }

            return $this->processLedgers($blogId, $date, $ledgers);
        });
    }

    /**
     * Execute calculation with pre-fetched ledgers
     *
     * @param int $blogId Blog ID
     * @param string $date Ngày (Y-m-d)
     * @param array $ledgers Pre-fetched ledgers
     * @return array ['saved_ids' => array]
     */
    public function executeWithLedgers(int $blogId, string $date, array $ledgers): array
    {
        return $this->blogContext->executeInBlog($blogId, function() use ($blogId, $date, $ledgers) {
            if (empty($ledgers)) {
                return ['saved_ids' => []];
            }
            return $this->processLedgers($blogId, $date, $ledgers);
        });
    }

    /**
     * Process ledgers and save roll-up data
     *
     * @param int $blogId Blog ID
     * @param string $date Date
     * @param array $ledgers Ledgers to process
     * @return array Result
     */
    private function processLedgers(int $blogId, string $date, array $ledgers): array
    {
        global $wpdb;
        
        $ledger_ids = array_column($ledgers, 'local_ledger_id');

        // Truyền toàn bộ ledgers thay vì chỉ ledger_ids để lấy item IDs từ JSON
        $items = $this->dataSource->getLedgerItems($ledgers);

        // Tạo map ledger_id => ledger để lấy parent_id và type
        $ledgerMap = [];
        $parentIds = [];
        foreach ($ledgers as $ledger) {
            $ledgerId = intval($ledger['local_ledger_id']);
            $ledgerMap[$ledgerId] = $ledger;
            
            // Thu thập parent IDs để query một lần
            $parentId = isset($ledger['local_ledger_parent_id']) ? intval($ledger['local_ledger_parent_id']) : null;
            if ($parentId && $parentId > 0) {
                $parentIds[] = $parentId;
            }
        }
        
        // Query tất cả parent một lần để tối ưu (lấy cả type và source)
        $parentMap = [];
        if (!empty($parentIds)) {
            $parentIds = array_unique($parentIds);
            $placeholders = implode(',', array_fill(0, count($parentIds), '%d'));
            $parentResults = $wpdb->get_results($wpdb->prepare(
                "SELECT local_ledger_id, local_ledger_type, local_ledger_source FROM " . TGS_TABLE_LOCAL_LEDGER . " WHERE local_ledger_id IN ({$placeholders})",
                ...$parentIds
            ), ARRAY_A);
            
            foreach ($parentResults as $parent) {
                $parentMap[intval($parent['local_ledger_id'])] = [
                    'type' => intval($parent['local_ledger_type']),
                    'source' => isset($parent['local_ledger_source']) ? intval($parent['local_ledger_source']) : 0
                ];
            }
        }

        $roll_up_data = [];

        // Lặp trực tiếp qua từng item
        foreach ($items as $item) {
            $product_id = $item['local_product_name_id'];
            $child_ledger_id = intval($item['local_ledger_id']);
            $child_ledger = $ledgerMap[$child_ledger_id] ?? null;
            
            if (!$child_ledger) {
                continue;
            }
            
            $child_type = intval($child_ledger['local_ledger_type']);
            $parent_id = isset($child_ledger['local_ledger_parent_id']) ? intval($child_ledger['local_ledger_parent_id']) : null;
            
            // Xác định roll-up type dựa vào parent type:
            // - Type 1 (nhập) từ parent type 11 (hoàn) → RETURN_ROLL_UP (11) - CHỈ TÍNH HOÀN HÀNG
            // - Type 2 (xuất) từ parent type 10 (bán) → SALES_ROLL_UP (10)
            $ledger_type = null;
            $source = 0;
            
            if ($parent_id && isset($parentMap[$parent_id])) {
                $parent_data = $parentMap[$parent_id];
                $parent_type = $parent_data['type'];
                $source = $parent_data['source']; // Lấy source từ parent ledger
                
                if ($child_type == 1) {
                    // Nhập kho - CHỈ tính khi parent type = 11 (hoàn hàng)
                    if ($parent_type == 11) {
                        $ledger_type = TGS_LEDGER_TYPE_RETURN_ROLL_UP; // 11 - Hoàn trả
                    }
                    // Không tính parent type = 9 (mua hàng)
                } elseif ($child_type == 2) {
                    // Xuất kho
                    if ($parent_type == 10) {
                        $ledger_type = TGS_LEDGER_TYPE_SALES_ROLL_UP; // 10 - Bán hàng
                    }
                }
            }
            
            // Nếu không xác định được type, bỏ qua
            if (!$ledger_type) {
                continue;
            }
            $key = $product_id . '_' . $ledger_type . '_' . $source;

            if (!isset($roll_up_data[$key])) {
                $roll_up_data[$key] = [
                    'blog_id' => $blogId,
                    'roll_up_date' => $date,
                    'local_product_name_id' => $product_id,
                    'global_product_name_id' => $item['global_product_name_id'] ?? null,
                    'type' => $ledger_type,
                    'source' => $source,
                    'amount_after_tax' => 0,
                    'tax' => 0,
                    'quantity' => 0,
                    'lot_ids' => [],
                    'ledger_ids' => [],
                ];
            }

            // Lưu ledger_id
            if (!empty($item['local_ledger_id'])) {
                $roll_up_data[$key]['ledger_ids'][] = intval($item['local_ledger_id']);
            }

            // Tính toán từ các trường thực tế trong local_ledger_item
            $quantity = floatval($item['quantity'] ?? 0);
            $price = floatval($item['price'] ?? 0);
            $tax = floatval($item['local_ledger_item_tax_amount'] ?? 0);

            // Công thức theo yêu cầu:
            // amount_after_tax += price * quantity + local_ledger_item_tax_amount
            // tax += local_ledger_item_tax_amount
            // quantity += quantity
            $amount_after_tax = ($price * $quantity) + $tax;

            $roll_up_data[$key]['amount_after_tax'] += $amount_after_tax;
            $roll_up_data[$key]['tax'] += $tax;
            $roll_up_data[$key]['quantity'] += $quantity;

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

        $saved_ids = [];
        foreach ($roll_up_data as $data) {
            $data['lot_ids'] = array_unique($data['lot_ids']);
            $data['ledger_ids'] = array_unique($data['ledger_ids']);

            $roll_up_id = $this->rollUpRepo->save($data, false);
            if ($roll_up_id) {
                $saved_ids[] = $roll_up_id;
            }
        }

        return [
            'saved_ids' => $saved_ids,
            'ledger_ids' => $ledger_ids,
        ];
    }

    /**
     * Lấy danh sách types cần xử lý
     * 
     * Logic: Lấy type 1, 2 (nhập/xuất kho - child ledgers) đã approve
     * Sau đó xác định parent type để phân loại roll-up type
     *
     * @param int|null $syncType
     * @return array
     */
    private function getTypesToProcess(?int $syncType): array
    {
        if ($syncType !== null) {
            return [$syncType];
        }

        return [
            TGS_LEDGER_TYPE_IMPORT_ROLL_UP,  // 1 - Nhập kho (từ mua hàng hoặc hoàn trả)
            TGS_LEDGER_TYPE_EXPORT_ROLL_UP,  // 2 - Xuất kho (từ bán hàng)
        ];
    }
}
