<?php
/**
 * Data Source Interface
 * Abstraction cho việc lấy dữ liệu từ external tables
 *
 * @package TGS_Sync_Roll_Up
 * @since 2.0.0
 */

if (!defined('ABSPATH')) {
    exit;
}

interface DataSourceInterface
{
    /**
     * Lấy danh sách ledgers theo ngày và loại
     *
     * @param string $date Ngày (Y-m-d)
     * @param array $types Mảng các loại ledger (optional)
     * @param bool $processedOnly Chỉ lấy records chưa được cron (is_croned = 0)
     * @return array Danh sách ledgers
     */
    public function getLedgers(string $date, array $types = [], bool $processedOnly = false): array;

    /**
     * Lấy ledger items từ ledgers
     * Parse cột local_ledger_item_id (JSON format) để lấy item IDs
     *
     * @param array $ledgers Mảng ledger records (chứa cột local_ledger_item_id)
     * @return array Danh sách ledger items
     */
    public function getLedgerItems(array $ledgers): array;

    /**
     * Lấy danh sách products
     *
     * @param array $productIds Mảng product IDs (optional)
     * @return array Danh sách products
     */
    public function getProducts(array $productIds = []): array;

    /**
     * Lấy product lots theo product IDs
     *
     * @param array $productIds Mảng product IDs
     * @return array Danh sách product lots
     */
    public function getProductLots(array $productIds = []): array;

    /**
     * Đánh dấu ledgers đã được xử lý
     *
     * @param array $ledgerIds Mảng ledger IDs
     * @return bool Success or failure
     */
    public function markLedgersAsProcessed(array $ledgerIds): bool;

    /**
     * Kiểm tra xem data source có sẵn sàng không
     *
     * @return bool True nếu tables tồn tại và accessible
     */
    public function isAvailable(): bool;
}
