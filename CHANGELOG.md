# Changelog - TGS Sync Roll Up Plugin

## Version 1.0.0 (Initial Release)

### Thiết kế theo yêu cầu của Sếp

#### ✅ Đã loại bỏ bảng `roll_up_monthly`
- **Lý do**: "Ko cần đến bảng roll_up_monthly...muốn tính báo cáo theo tháng hay tuần thì tự nhóm lại là ra"
- **Giải pháp**: Sử dụng GROUP BY trên các cột `roll_up_day`, `roll_up_month`, `roll_up_year`

#### ✅ Đã thay đổi cột HSD từ cố định sang JSON linh hoạt
- **Lý do**: "Sếp ko thích theo kiểu cố định ngày như này, ko linh hoạt ý...muốn linh hoạt hơn, đơn giản mà hiệu quả"
- **Trước đây** (cột cố định):
  ```sql
  inventory_expiring_7days INT
  inventory_expiring_30days INT
  inventory_expiring_90days INT
  inventory_expired INT
  ```
- **Bây giờ** (JSON linh hoạt):
  ```sql
  inventory_expiry_summary JSON
  ```
- **Format JSON**:
  ```json
  {
    "by_range": {
      "expired": {"lot_count": 5, "quantity": 100},
      "0_7_days": {"lot_count": 10, "quantity": 200},
      "8_30_days": {"lot_count": 15, "quantity": 300},
      "31_90_days": {"lot_count": 20, "quantity": 400},
      "over_90_days": {"lot_count": 50, "quantity": 1000}
    },
    "total_with_expiry": 100,
    "calculated_at": "2025-01-15 10:30:00"
  }
  ```

#### ✅ Đã thêm 3 cột mới để GROUP BY linh hoạt
- `roll_up_day` (TINYINT): Ngày trong tháng (1-31)
- `roll_up_month` (TINYINT): Tháng trong năm (1-12)
- `roll_up_year` (SMALLINT): Năm (YYYY)

### Cách sử dụng báo cáo linh hoạt

#### Báo cáo theo tháng:
```sql
SELECT 
    roll_up_year, roll_up_month,
    SUM(revenue_total) as total_revenue,
    SUM(count_sales_orders) as total_orders
FROM wp_roll_up
WHERE blog_id = 1
GROUP BY roll_up_year, roll_up_month
ORDER BY roll_up_year DESC, roll_up_month DESC;
```

#### Báo cáo theo tuần:
```sql
SELECT 
    YEAR(roll_up_date) as year,
    WEEK(roll_up_date) as week_num,
    SUM(revenue_total) as total_revenue
FROM wp_roll_up
WHERE blog_id = 1
GROUP BY YEAR(roll_up_date), WEEK(roll_up_date);
```

#### Báo cáo theo năm:
```sql
SELECT 
    roll_up_year,
    SUM(revenue_total) as total_revenue,
    SUM(count_sales_orders) as total_orders
FROM wp_roll_up
WHERE blog_id = 1
GROUP BY roll_up_year;
```

### PHP Methods

#### Lấy tổng theo tháng:
```php
$calculator = new TGS_Roll_Up_Calculator();
$monthly = $calculator->get_monthly_summary($blog_id, '2025-01-01');
```

#### Lấy tổng theo tuần:
```php
$calculator = new TGS_Roll_Up_Calculator();
$weekly = $calculator->get_weekly_summary($blog_id, '2025-01-13', '2025-01-19');
```

#### Lấy tổng theo năm:
```php
$calculator = new TGS_Roll_Up_Calculator();
$yearly = $calculator->get_yearly_summary($blog_id, 2025);
```

---

## Cấu trúc Plugin

```
tgs_sync_roll_up/
├── tgs-sync-roll-up.php         # Main plugin file
├── includes/
│   ├── class-database.php       # Database class (sync config table)
│   ├── class-data-collector.php # Collect data from local tables
│   ├── class-roll-up-calculator.php # Calculate statistics
│   ├── class-sync-manager.php   # Sync to parent shops
│   └── class-cron-handler.php   # WP Cron handling
├── admin/
│   ├── views/
│   │   ├── dashboard.php        # Main dashboard view
│   │   ├── settings-page.php    # Settings page
│   │   └── logs-page.php        # Sync logs page
│   ├── css/
│   │   └── admin.css            # Admin styles
│   └── js/
│       └── admin.js             # Admin JavaScript
└── CHANGELOG.md                 # This file
```

## Tables

### 1. roll_up (Daily statistics)
- Chứa thống kê hàng ngày cho mỗi shop
- Có các cột `roll_up_day`, `roll_up_month`, `roll_up_year` để GROUP BY linh hoạt
- HSD lưu dạng JSON trong cột `inventory_expiry_summary`

### 2. roll_up_meta (Detailed metadata)
- Chứa chi tiết phức tạp như top products, revenue by category
- Liên kết với roll_up qua `roll_up_id`

### 3. sync_roll_up_config (Sync configuration)
- Cấu hình sync cho mỗi shop
- Chứa thông tin parent_blog_id, sync frequency, etc.
