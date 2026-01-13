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
     * Execute calculation
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

            $ledger_ids = array_column($ledgers, 'local_ledger_id');

            // Truyền toàn bộ ledgers thay vì chỉ ledger_ids để lấy item IDs từ JSON
            $items = $this->dataSource->getLedgerItems($ledgers);
            error_log("12313");
            error_log(json_encode($items));
            $items_by_ledger = [];
            foreach ($items as $item) {
                $ledger_id = $item['local_ledger_id'];
                if (!isset($items_by_ledger[$ledger_id])) {
                    $items_by_ledger[$ledger_id] = [];
                }
                $items_by_ledger[$ledger_id][] = $item;
            }

            $roll_up_data = [];

            foreach ($ledgers as $ledger) {
                $ledger_id = $ledger['local_ledger_id'];
                $ledger_type = intval($ledger['local_ledger_type']);

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

                    // Tính toán từ các trường thực tế trong local_ledger_item
                    $quantity = floatval($item['quantity'] ?? 0);
                    $price = floatval($item['price'] ?? 0);
                    $tax = floatval($item['local_ledger_item_tax_amount'] ?? 0);

                    // Công thức theo yêu cầu:
                    // amount_after_tax += price * quantity - local_ledger_item_tax_amount
                    // tax += local_ledger_item_tax_amount
                    // quantity += quantity
                    $amount_after_tax = ($price * $quantity) - $tax;

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
            }

            error_log(json_encode($roll_up_data));
            $saved_ids = [];
            foreach ($roll_up_data as $data) {
                $data['lot_ids'] = array_unique($data['lot_ids']);

                $roll_up_id = $this->rollUpRepo->save($data, false);
                if ($roll_up_id) {
                    $saved_ids[] = $roll_up_id;
                }
            }

            // Không đánh cờ is_croned ở đây nữa, trả về ledger_ids để CronService xử lý
            return [
                'saved_ids' => $saved_ids,
                'ledger_ids' => $ledger_ids,
            ];
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

        return [
            TGS_LEDGER_TYPE_SALES,     // 10 - Bán hàng (dùng cho dashboard & order)
            11,    // 11 - Hoàn trả (dùng cho dashboard)
        ];
    }
}
