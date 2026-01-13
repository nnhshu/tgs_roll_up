# TGS Sync Roll-Up - Code Examples

## 📘 Hướng dẫn sử dụng API mới

---

## 1. 🧮 **Tính Roll-Up cho một ngày**

### Basic Usage

```php
// Lấy use case từ container
$calculateUseCase = ServiceContainer::make(CalculateDailyRollUp::class);

// Tính roll-up cho ngày hôm nay
$blogId = get_current_blog_id();
$date = current_time('Y-m-d');

try {
    $savedIds = $calculateUseCase->execute($blogId, $date);
    echo "Đã tạo " . count($savedIds) . " roll-up records!";
} catch (Exception $e) {
    error_log("Roll-up failed: " . $e->getMessage());
}
```

### Tính cho một loại ledger cụ thể

```php
$calculateUseCase = ServiceContainer::make(CalculateDailyRollUp::class);

// Chỉ tính sales (type 10)
$savedIds = $calculateUseCase->execute($blogId, $date, TGS_LEDGER_TYPE_SALES);

// Chỉ tính inventory import (type 1)
$savedIds = $calculateUseCase->execute($blogId, $date, TGS_LEDGER_TYPE_IMPORT);
```

---

## 2. 🔄 **Sync dữ liệu lên Shop Cha**

### Basic Sync

```php
$syncUseCase = ServiceContainer::make(SyncToParentShop::class);

$result = $syncUseCase->execute(get_current_blog_id(), '2024-01-15');

if (!empty($result['success'])) {
    echo "Sync thành công! Đã sync {$result['total_synced']} records.";
} else {
    echo "Sync thất bại: " . $result['message'];
}
```

### Sync với error handling

```php
try {
    $syncUseCase = ServiceContainer::make(SyncToParentShop::class);
    $result = $syncUseCase->execute($childBlogId, $date);

    // Check kết quả
    if ($result['total_synced'] > 0) {
        // Success
        $parentId = $result['success'][0]['parent_blog_id'];
        error_log("Synced {$result['total_synced']} records to parent blog {$parentId}");
    } else {
        // No data hoặc failed
        if (!empty($result['failed'])) {
            foreach ($result['failed'] as $failure) {
                error_log("Sync failed: " . $failure['error']);
            }
        }
    }
} catch (Exception $e) {
    error_log("Exception during sync: " . $e->getMessage());
}
```

---

## 3. 🗄️ **Sử dụng Repositories**

### Lưu roll-up data

```php
$repo = ServiceContainer::make(RollUpRepositoryInterface::class);

$data = [
    'blog_id' => 5,
    'roll_up_date' => '2024-01-15',
    'local_product_name_id' => 123,
    'type' => TGS_LEDGER_TYPE_SALES,
    'amount_after_tax' => 500000,
    'tax' => 50000,
    'quantity' => 10,
    'lot_ids' => [1, 2, 3],
];

$rollUpId = $repo->save($data, false); // false = merge with existing
```

### Query roll-up data

```php
$repo = ServiceContainer::make(RollUpRepositoryInterface::class);

// Lấy data cho một ngày
$records = $repo->findByBlogAndDate(5, '2024-01-15');

// Tính tổng cho date range
$summary = $repo->sumByDateRange(5, '2024-01-01', '2024-01-31');
echo "Total revenue: " . $summary['revenue'];
```

---

## 4. 🔌 **Đăng ký Custom Sync Type**

### Example: Sync Customer Lifetime Value

```php
add_action('plugins_loaded', function() {
    tgs_register_sync_type('customer_ltv', function($blogId, $date, $args) {
        // Get data source
        $dataSource = ServiceContainer::make(DataSourceInterface::class);

        // Lấy tất cả customers đã mua hàng trong ngày
        $ledgers = $dataSource->getLedgers($date, [TGS_LEDGER_TYPE_SALES]);

        $customerLTV = [];

        foreach ($ledgers as $ledger) {
            $customerId = $ledger['local_ledger_person_id'] ?? null;
            if (!$customerId) continue;

            // Tính tổng purchase của customer này (từ đầu đến giờ)
            global $wpdb;
            $table = TGS_TABLE_LOCAL_LEDGER;
            $totalSpent = $wpdb->get_var($wpdb->prepare(
                "SELECT COALESCE(SUM(amount_after_tax), 0)
                 FROM {$table}
                 WHERE local_ledger_person_id = %d
                 AND type = %d
                 AND DATE(date_created) <= %s",
                $customerId,
                TGS_LEDGER_TYPE_SALES,
                $date
            ));

            $customerLTV[$customerId] = floatval($totalSpent);
        }

        // Lưu vào custom table hoặc options
        update_option("customer_ltv_{$blogId}_{$date}", $customerLTV);

        return [
            'customers_count' => count($customerLTV),
            'total_ltv' => array_sum($customerLTV),
            'avg_ltv' => count($customerLTV) > 0 ? array_sum($customerLTV) / count($customerLTV) : 0,
        ];
    }, [
        'label' => 'Customer Lifetime Value',
        'description' => 'Calculate LTV for all customers up to a specific date',
        'icon' => 'dashicons-chart-line',
        'priority' => 20,
    ]);
}, 100);
```

### Execute custom sync

```php
$result = tgs_execute_sync('customer_ltv', get_current_blog_id(), current_time('Y-m-d'));

echo "Processed {$result['customers_count']} customers";
echo "Average LTV: " . number_format($result['avg_ltv'], 0);
```

---

## 5. 🎣 **WordPress Hooks Integration**

### Send Slack notification sau khi sync

```php
add_action('tgs_sync_completed', function($result, $context) {
    if ($result['total_synced'] > 0) {
        $message = sprintf(
            "✅ *Sync Completed*\nBlog: %d\nDate: %s\nRecords: %d",
            $result['source_blog_id'],
            $result['date'],
            $result['total_synced']
        );

        wp_remote_post('https://hooks.slack.com/services/YOUR/WEBHOOK', [
            'body' => json_encode(['text' => $message]),
            'headers' => ['Content-Type' => 'application/json'],
        ]);
    }
}, 10, 2);
```

### Log analytics trước khi calculate

```php
add_filter('tgs_sync_before_calculate', function($data, $blogId, $date) {
    // Track event trong Google Analytics
    do_action('track_analytics_event', 'roll_up_calculate', [
        'blog_id' => $blogId,
        'date' => $date,
        'ledger_count' => count($data),
    ]);

    return $data; // Phải return data
}, 10, 3);
```

### Modify data trước khi lưu

```php
add_filter('tgs_sync_modify_data', function($data, $type) {
    // Apply discount 10% cho strategic products
    if (isset($data['global_product_name_id'])) {
        $product = get_product($data['global_product_name_id']);

        if ($product && $product->tag == TGS_PRODUCT_TAG_STRATEGIC) {
            $data['amount_after_tax'] *= 0.9; // Apply 10% discount
        }
    }

    return $data;
}, 10, 2);
```

### Error handling và alerts

```php
add_action('tgs_sync_failed', function($error, $blogId, $date) {
    // Send email alert
    $adminEmail = get_option('admin_email');
    $subject = "TGS Sync Failed - Blog {$blogId}";
    $body = "
        Sync failed with error:
        Date: {$date}
        Error: {$error}

        Please check the logs for more details.
    ";

    wp_mail($adminEmail, $subject, $body);

    // Log to external service (ví dụ: Sentry)
    if (function_exists('sentry_log')) {
        sentry_log([
            'level' => 'error',
            'message' => 'TGS Sync Failed',
            'context' => [
                'blog_id' => $blogId,
                'date' => $date,
                'error' => $error,
            ],
        ]);
    }
}, 10, 3);
```

---

## 6. 🌐 **Multi-Site Operations với BlogContext**

### Execute trong context của blog khác

```php
$blogContext = ServiceContainer::make('BlogContext');

$result = $blogContext->executeInBlog(5, function() {
    // Code này chạy trong context của blog 5
    $option = get_option('my_custom_option');
    return $option;
});

echo "Option value from blog 5: " . $result;
```

### Batch processing multiple blogs

```php
$blogContext = ServiceContainer::make('BlogContext');
$blogIds = [2, 3, 5, 7];

$results = $blogContext->executeInMultipleBlogs($blogIds, function($blogId) {
    // Tính roll-up cho mỗi blog
    $calculateUseCase = ServiceContainer::make(CalculateDailyRollUp::class);
    return $calculateUseCase->execute($blogId, current_time('Y-m-d'));
});

foreach ($results as $blogId => $result) {
    if (isset($result['error'])) {
        echo "Blog {$blogId}: ERROR - {$result['error']}\n";
    } else {
        echo "Blog {$blogId}: Success - " . count($result) . " records\n";
    }
}
```

---

## 7. 📊 **Advanced: Custom Data Source**

### Tạo custom data source cho API bên ngoài

```php
class ExternalApiDataSource implements DataSourceInterface {
    private $apiUrl;
    private $apiKey;

    public function __construct(string $apiUrl, string $apiKey) {
        $this->apiUrl = $apiUrl;
        $this->apiKey = $apiKey;
    }

    public function getLedgers(string $date, array $types = [], bool $processedOnly = false): array {
        $response = wp_remote_get($this->apiUrl . '/ledgers', [
            'headers' => ['Authorization' => 'Bearer ' . $this->apiKey],
            'body' => ['date' => $date, 'types' => $types],
        ]);

        if (is_wp_error($response)) {
            return [];
        }

        return json_decode(wp_remote_retrieve_body($response), true) ?? [];
    }

    public function isAvailable(): bool {
        $response = wp_remote_head($this->apiUrl . '/health');
        return !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;
    }

    // Implement other interface methods...
}

// Đăng ký custom data source
ServiceContainer::bind(DataSourceInterface::class, function() {
    return new ExternalApiDataSource(
        'https://api.example.com',
        get_option('external_api_key')
    );
}, true);
```

---

## 8. 🧪 **Testing Examples**

### Mock dependencies cho unit test

```php
class RollUpCalculatorTest extends WP_UnitTestCase {
    public function test_calculate_handles_empty_ledgers() {
        // Mock DataSource
        $dataSource = Mockery::mock(DataSourceInterface::class);
        $dataSource->shouldReceive('isAvailable')->andReturn(true);
        $dataSource->shouldReceive('getLedgers')->andReturn([]);

        // Mock Repository
        $repo = Mockery::mock(RollUpRepositoryInterface::class);

        // Create use case với mocked dependencies
        $useCase = new CalculateDailyRollUp(
            $dataSource,
            $repo,
            new BlogContext()
        );

        // Execute và verify
        $result = $useCase->execute(1, '2024-01-15');
        $this->assertEmpty($result);
    }
}
```

---

## 🎯 **Best Practices**

1. **Luôn sử dụng ServiceContainer** để lấy dependencies
2. **Catch exceptions** khi gọi use cases
3. **Sử dụng WordPress hooks** để extend functionality
4. **Log errors** để debug dễ dàng
5. **Test với multiple blogs** trước khi deploy
6. **Document custom sync types** cho team members

---

**Happy Coding!** 🚀
