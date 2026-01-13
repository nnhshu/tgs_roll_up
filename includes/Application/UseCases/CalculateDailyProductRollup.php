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
        $ledger_ids = array_column($ledgers, 'local_ledger_id');

        // Truyền toàn bộ ledgers thay vì chỉ ledger_ids để lấy item IDs từ JSON
        $items = $this->dataSource->getLedgerItems($ledgers);

        $roll_up_data = [];

        // Lặp trực tiếp qua từng item, không cần map qua ledgers
        // vì items thuộc về child ledgers, không phải parent ledgers
        foreach ($items as $item) {
            $product_id = $item['local_product_name_id'];
            // Type cố định là 10 (SALES) cho product roll-up
            $ledger_type = TGS_LEDGER_TYPE_SALES;
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

        $saved_ids = [];
        foreach ($roll_up_data as $data) {
            $data['lot_ids'] = array_unique($data['lot_ids']);

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
