# TGS Sync Roll-Up - Architecture Documentation

## 📐 Tổng quan kiến trúc

Plugin được refactor theo **Clean Architecture** với 4 layers chính:

```
┌─────────────────────────────────────────────────────────────┐
│                    Presentation Layer                       │
│  (Controllers, AJAX Handlers, Views)                        │
└─────────────────┬───────────────────────────────────────────┘
                  │
┌─────────────────▼───────────────────────────────────────────┐
│                   Application Layer                         │
│  (Use Cases, Business Logic Orchestration)                  │
└─────────────────┬───────────────────────────────────────────┘
                  │
┌─────────────────▼───────────────────────────────────────────┐
│                      Core Layer                             │
│  (Domain Entities, Interfaces, Services)                    │
└─────────────────┬───────────────────────────────────────────┘
                  │
┌─────────────────▼───────────────────────────────────────────┐
│                 Infrastructure Layer                        │
│  (Database, External APIs, MultiSite)                       │
└─────────────────────────────────────────────────────────────┘
```

---

## 🗂️ Cấu trúc thư mục

```
includes/
├── Core/                              # Domain layer
│   ├── Interfaces/                    # Contracts/Interfaces
│   │   ├── DataSourceInterface.php
│   │   ├── RollUpRepositoryInterface.php
│   │   └── ConfigRepositoryInterface.php
│   └── ServiceContainer.php           # DI Container
│
├── Infrastructure/                    # Implementation details
│   ├── Database/
│   │   └── Repositories/
│   │       ├── ProductRollUpRepository.php
│   │       └── ConfigRepository.php
│   ├── External/
│   │   └── TgsShopDataSource.php      # Adapter cho tgs_shop_management
│   └── MultiSite/
│       └── BlogContext.php            # Wrapper cho switch_to_blog()
│
├── Application/                       # Use cases
│   └── UseCases/
│       ├── CalculateDailyRollUp.php
│       └── SyncToParentShop.php
│
├── Presentation/                      # UI layer
│   └── Ajax/
│       └── SyncAjaxHandler.php
│
└── Extensions/                        # Extensibility
    ├── SyncTypeRegistry.php
    └── FilterHooks.php
```

---

## 🔧 Design Patterns

### 1. **Repository Pattern**
Abstraction cho data access, dễ dàng swap implementations.

```php
interface RollUpRepositoryInterface {
    public function save(array $data, bool $overwrite = false): int;
    public function findByBlogAndDate(int $blogId, string $date): ?array;
}

class ProductRollUpRepository implements RollUpRepositoryInterface {
    // Implementation
}
```

### 2. **Dependency Injection**
Sử dụng ServiceContainer để manage dependencies.

```php
ServiceContainer::singleton(RollUpRepositoryInterface::class, function() {
    return new ProductRollUpRepository();
});

$repo = ServiceContainer::make(RollUpRepositoryInterface::class);
```

### 3. **Adapter Pattern**
Decouple từ external plugin tables.

```php
interface DataSourceInterface {
    public function getLedgers(string $date, array $types): array;
}

class TgsShopDataSource implements DataSourceInterface {
    // Wrap tgs_shop_management tables
}
```

### 4. **Strategy Pattern**
Flexible sync types.

```php
SyncTypeRegistry::register('products', function($blogId, $date) {
    // Product sync logic
});

SyncTypeRegistry::register('custom_metric', function($blogId, $date) {
    // Custom logic
});
```

### 5. **Service Layer**
Orchestrate business logic.

```php
class CalculateDailyRollUp {
    public function execute(int $blogId, string $date) {
        // Coordinate data fetching, calculation, persistence
    }
}
```

---

## 🚀 Cách sử dụng

### Tính roll-up cho một ngày

```php
$calculateUseCase = ServiceContainer::make(CalculateDailyRollUp::class);
$savedIds = $calculateUseCase->execute(get_current_blog_id(), '2024-01-15');
```

### Sync lên shop cha

```php
$syncUseCase = ServiceContainer::make(SyncToParentShop::class);
$result = $syncUseCase->execute(get_current_blog_id(), '2024-01-15');
```

### Đăng ký custom sync type

```php
tgs_register_sync_type('sales_by_region', function($blogId, $date) {
    // Custom calculation logic
    return ['status' => 'success'];
}, [
    'label' => 'Sales by Region',
    'description' => 'Calculate sales grouped by region',
    'icon' => 'dashicons-location',
    'priority' => 20,
]);
```

### Sử dụng WordPress hooks

```php
// Before calculate
add_filter('tgs_sync_before_calculate', function($data, $blogId, $date) {
    // Modify data trước khi tính
    return $data;
}, 10, 3);

// After sync completed
add_action('tgs_sync_completed', function($result, $context) {
    // Send notification, log analytics, etc.
}, 10, 2);
```

---

## 🔌 Extensibility

### Thêm một sync type mới

1. **Đăng ký trong plugin init:**

```php
add_action('plugins_loaded', function() {
    tgs_register_sync_type('inventory_expiry', function($blogId, $date, $args) {
        // Logic để tính inventory sắp hết hạn
        $dataSource = ServiceContainer::make(DataSourceInterface::class);
        $lots = $dataSource->getProductLots();

        // Filter lots sắp hết hạn (< 30 ngày)
        $expiring = array_filter($lots, function($lot) use ($date) {
            $expiryDate = strtotime($lot['expiry_date']);
            $currentDate = strtotime($date);
            $daysRemaining = ($expiryDate - $currentDate) / 86400;
            return $daysRemaining < 30 && $daysRemaining > 0;
        });

        return [
            'count' => count($expiring),
            'total_value' => array_sum(array_column($expiring, 'value')),
        ];
    }, [
        'label' => 'Inventory Expiry Alert',
        'description' => 'Track products expiring in next 30 days',
        'icon' => 'dashicons-warning',
        'priority' => 15,
    ]);
}, 100);
```

2. **Execute:**

```php
$result = tgs_execute_sync('inventory_expiry', get_current_blog_id(), current_time('Y-m-d'));
```

### Hook vào lifecycle events

```php
// Trước khi tính roll-up
add_filter('tgs_sync_before_calculate', function($data, $blogId, $date) {
    // Log analytics event
    do_action('log_analytics', 'roll_up_calculate_start', [
        'blog_id' => $blogId,
        'date' => $date,
    ]);

    return $data;
}, 10, 3);

// Sau khi sync xong
add_action('tgs_sync_completed', function($result, $context) {
    // Gửi Slack notification
    if ($result['total_synced'] > 0) {
        wp_remote_post('https://hooks.slack.com/services/YOUR/WEBHOOK/URL', [
            'body' => json_encode([
                'text' => sprintf('✅ Synced %d records for blog %d',
                    $result['total_synced'],
                    $result['source_blog_id']
                ),
            ]),
        ]);
    }
}, 10, 2);

// Khi sync thất bại
add_action('tgs_sync_failed', function($error, $blogId, $date) {
    // Alert admins
    wp_mail(
        get_option('admin_email'),
        'TGS Sync Failed',
        sprintf('Sync failed for blog %d on %s: %s', $blogId, $date, $error)
    );
}, 10, 3);
```

---

## 🧪 Testing

### Unit Test Example (future)

```php
class CalculateDailyRollUpTest extends WP_UnitTestCase {
    public function test_calculate_sales_revenue() {
        // Mock dependencies
        $dataSource = Mockery::mock(DataSourceInterface::class);
        $dataSource->shouldReceive('getLedgers')
            ->once()
            ->andReturn([/* test data */]);

        $repo = Mockery::mock(RollUpRepositoryInterface::class);
        $repo->shouldReceive('save')
            ->once()
            ->andReturn(123);

        // Test use case
        $useCase = new CalculateDailyRollUp($dataSource, $repo, new BlogContext());
        $result = $useCase->execute(1, '2024-01-15', TGS_LEDGER_TYPE_SALES);

        $this->assertNotEmpty($result);
    }
}
```

---

## 📊 Benefits

### ✅ **Maintainability**
- Code được tổ chức theo layers rõ ràng
- Single Responsibility Principle
- Dễ tìm và fix bugs

### ✅ **Testability**
- Dependency Injection → dễ mock
- Repository pattern → test không cần database
- Use Cases isolated → unit test riêng lẻ

### ✅ **Extensibility**
- Registry pattern → thêm sync types mới không sửa core
- WordPress hooks → third-party plugins có thể extend
- Interface-based → swap implementations

### ✅ **Scalability**
- Thêm features mới không ảnh hưởng core
- Support thêm data sources
- Multi-level hierarchy có thể mở rộng

### ✅ **Decoupling**
- Không phụ thuộc cứng vào external plugin tables
- BlogContext wrapper → multi-site logic tách biệt
- Repository → swap database implementations

---

## 🔄 Migration Path

Plugin hiện vẫn giữ **backward compatibility** với old classes:

1. **Phase 1** (Hoàn thành): New architecture coexists với legacy code
2. **Phase 2** (Tiếp theo): Migrate legacy classes sang use cases
3. **Phase 3** (Cuối cùng): Remove legacy code hoàn toàn

Legacy classes vẫn hoạt động bình thường, nhưng AJAX handlers mới sử dụng new architecture.

---

## 📚 Further Reading

- [Clean Architecture by Robert C. Martin](https://blog.cleancoder.com/uncle-bob/2012/08/13/the-clean-architecture.html)
- [Repository Pattern](https://designpatternsphp.readthedocs.io/en/latest/More/Repository/README.html)
- [Dependency Injection](https://phptherightway.com/#dependency_injection)

---

**Tác giả**: TGS Development Team
**Phiên bản**: 2.0.0
**Ngày cập nhật**: 2026-01-13
