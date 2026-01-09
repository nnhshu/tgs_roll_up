<?php
/**
 * Dashboard View
 *
 * @package TGS_Sync_Roll_Up
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap tgs-sync-dashboard">
    <h1><?php esc_html_e('TGS Sync Roll Up - Dashboard', 'tgs-sync-roll-up'); ?></h1>

    <!-- Status Cards -->
    <div class="tgs-status-cards">
        <div class="tgs-card tgs-card-primary">
            <div class="tgs-card-icon">
                <span class="dashicons dashicons-chart-area"></span>
            </div>
            <div class="tgs-card-content">
                <h3><?php esc_html_e('Doanh thu hôm nay', 'tgs-sync-roll-up'); ?></h3>
                <p class="tgs-card-value">
                    <?php echo TGS_Admin_Page::format_currency($today_total_revenue); ?>
                </p>
            </div>
        </div>


        <div class="tgs-card tgs-card-warning">
            <div class="tgs-card-icon">
                <span class="dashicons dashicons-update"></span>
            </div>
            <div class="tgs-card-content">
                <h3><?php esc_html_e('Trạng thái Sync', 'tgs-sync-roll-up'); ?></h3>
                <p class="tgs-card-value">
                    <?php if ($config && $config->sync_enabled): ?>
                        <span class="tgs-status-badge tgs-status-active"><?php esc_html_e('Đang hoạt động', 'tgs-sync-roll-up'); ?></span>
                    <?php else: ?>
                        <span class="tgs-status-badge tgs-status-inactive"><?php esc_html_e('Đã tắt', 'tgs-sync-roll-up'); ?></span>
                    <?php endif; ?>
                </p>
            </div>
        </div>
    </div>

    <!-- Charts Section -->
    <div class="tgs-charts-section">
        <div class="tgs-chart-container">
            <h2><?php esc_html_e('Doanh thu 7 ngày gần đây', 'tgs-sync-roll-up'); ?></h2>
            <canvas id="tgs-revenue-chart"></canvas>
        </div>
    </div>

    <!-- Quick Actions & Cron Info -->
    <div class="tgs-row">
        <div class="tgs-col-6">
            <div class="tgs-panel">
                <h2><?php esc_html_e('Thao tác nhanh', 'tgs-sync-roll-up'); ?></h2>
                <div class="tgs-quick-actions">
                    <button type="button" class="button button-primary" id="tgs-manual-sync-btn">
                        <span class="dashicons dashicons-update"></span>
                        <?php esc_html_e('Sync ngay', 'tgs-sync-roll-up'); ?>
                    </button>

                    <button type="button" class="button button-secondary" id="tgs-rebuild-btn">
                        <span class="dashicons dashicons-database"></span>
                        <?php esc_html_e('Rebuild Roll-up', 'tgs-sync-roll-up'); ?>
                    </button>

                    <a href="<?php echo esc_url(admin_url('admin.php?page=tgs-sync-roll-up-settings')); ?>" class="button">
                        <span class="dashicons dashicons-admin-settings"></span>
                        <?php esc_html_e('Cài đặt', 'tgs-sync-roll-up'); ?>
                    </a>
                </div>

                <!-- Rebuild Modal -->
                <div id="tgs-rebuild-modal" class="tgs-modal" style="display: none;">
                    <div class="tgs-modal-content">
                        <h3><?php esc_html_e('Rebuild Roll-up Data', 'tgs-sync-roll-up'); ?></h3>
                        <form id="tgs-rebuild-form">
                            <p>
                                <label for="rebuild-start-date"><?php esc_html_e('Từ ngày:', 'tgs-sync-roll-up'); ?></label>
                                <input type="date" id="rebuild-start-date" name="start_date" value="<?php echo esc_attr(date('Y-m-01')); ?>">
                            </p>
                            <p>
                                <label for="rebuild-end-date"><?php esc_html_e('Đến ngày:', 'tgs-sync-roll-up'); ?></label>
                                <input type="date" id="rebuild-end-date" name="end_date" value="<?php echo esc_attr(current_time('Y-m-d')); ?>">
                            </p>
                            <p>
                                <label>
                                    <input type="checkbox" name="sync_to_parents" value="1" checked>
                                    <?php esc_html_e('Sync lên shop cha sau khi rebuild', 'tgs-sync-roll-up'); ?>
                                </label>
                            </p>
                            <div class="tgs-modal-actions">
                                <button type="submit" class="button button-primary"><?php esc_html_e('Rebuild', 'tgs-sync-roll-up'); ?></button>
                                <button type="button" class="button tgs-modal-close"><?php esc_html_e('Hủy', 'tgs-sync-roll-up'); ?></button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>

        <div class="tgs-col-6">
            <div class="tgs-panel">
                <h2><?php esc_html_e('Thông tin Cron', 'tgs-sync-roll-up'); ?></h2>
                <table class="tgs-info-table">
                    <tr>
                        <td><strong><?php esc_html_e('Sync tiếp theo:', 'tgs-sync-roll-up'); ?></strong></td>
                        <td><?php echo $cron_info['sync']['next_run_formatted'] ?? '-'; ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Cleanup tiếp theo:', 'tgs-sync-roll-up'); ?></strong></td>
                        <td><?php echo $cron_info['cleanup']['next_run_formatted'] ?? '-'; ?></td>
                    </tr>
                    <tr>
                        <td><strong><?php esc_html_e('Monthly tiếp theo:', 'tgs-sync-roll-up'); ?></strong></td>
                        <td><?php echo $cron_info['monthly']['next_run_formatted'] ?? '-'; ?></td>
                    </tr>
                </table>
            </div>
        </div>
    </div>

    <!-- Recent Sync Logs -->
    <div class="tgs-panel">
        <h2><?php esc_html_e('Log sync gần đây', 'tgs-sync-roll-up'); ?></h2>
        <?php if (!empty($recent_logs)): ?>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php esc_html_e('Thời gian', 'tgs-sync-roll-up'); ?></th>
                        <th><?php esc_html_e('Ngày sync', 'tgs-sync-roll-up'); ?></th>
                        <th><?php esc_html_e('Thành công', 'tgs-sync-roll-up'); ?></th>
                        <th><?php esc_html_e('Thất bại', 'tgs-sync-roll-up'); ?></th>
                        <th><?php esc_html_e('Chi tiết', 'tgs-sync-roll-up'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_logs as $log): ?>
                        <tr>
                            <td><?php echo esc_html(TGS_Admin_Page::format_datetime($log['timestamp'])); ?></td>
                            <td><?php echo esc_html($log['date'] ?? '-'); ?></td>
                            <td>
                                <span class="tgs-badge tgs-badge-success"><?php echo esc_html($log['success_count'] ?? 0); ?></span>
                            </td>
                            <td>
                                <?php if (($log['failed_count'] ?? 0) > 0): ?>
                                    <span class="tgs-badge tgs-badge-danger"><?php echo esc_html($log['failed_count']); ?></span>
                                <?php else: ?>
                                    <span class="tgs-badge tgs-badge-success">0</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button type="button" class="button button-small tgs-view-log-details" 
                                        data-details="<?php echo esc_attr(json_encode($log['details'] ?? array())); ?>">
                                    <?php esc_html_e('Xem', 'tgs-sync-roll-up'); ?>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <p>
                <a href="<?php echo esc_url(admin_url('admin.php?page=tgs-sync-roll-up-logs')); ?>">
                    <?php esc_html_e('Xem tất cả log →', 'tgs-sync-roll-up'); ?>
                </a>
            </p>
        <?php else: ?>
            <p><?php esc_html_e('Chưa có log sync nào.', 'tgs-sync-roll-up'); ?></p>
        <?php endif; ?>
    </div>

    <!-- Children Summary Section -->
    <?php if (!empty($shops_syncing_to_me)):
        $configured_shop_count = count($shops_syncing_to_me);
    ?>
    <div class="tgs-panel" style="background: #f5fff5; border-left: 4px solid #46b450;">
        <h2><?php esc_html_e('👥 SHOP CON ĐANG SYNC LÊN ĐÂY', 'tgs-sync-roll-up'); ?></h2>
        <p class="description">
            <?php esc_html_e('Danh sách các shop con được cấu hình sync dữ liệu lên shop này', 'tgs-sync-roll-up'); ?>
            (<?php echo $configured_shop_count; ?> shop)
        </p>

        <table class="wp-list-table widefat fixed striped" style="margin-top: 15px;">
            <thead>
                <tr>
                    <th><?php esc_html_e('Shop con', 'tgs-sync-roll-up'); ?></th>
                    <th><?php esc_html_e('Blog ID', 'tgs-sync-roll-up'); ?></th>
                    <th><?php esc_html_e('Thao tác', 'tgs-sync-roll-up'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($shops_syncing_to_me as $shop_info): ?>
                <tr>
                    <td><strong><?php echo esc_html($shop_info['blog_name']); ?></strong></td>
                    <td><span style="color: #999;">#<?php echo esc_html($shop_info['blog_id']); ?></span></td>
                    <td>
                        <button type="button" class="button button-small tgs-view-child-detail"
                                data-blog-id="<?php echo esc_attr($shop_info['blog_id']); ?>"
                                data-shop-name="<?php echo esc_attr($shop_info['blog_name']); ?>">
                            <span class="dashicons dashicons-visibility" style="vertical-align: middle;"></span>
                            <?php esc_html_e('Xem chi tiết', 'tgs-sync-roll-up'); ?>
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

   
</div>

<script>
jQuery(document).ready(function($) {
    // Initialize charts
    var chartData = <?php echo json_encode($chart_data); ?>;

    // Revenue chart
    var revenueCtx = document.getElementById('tgs-revenue-chart').getContext('2d');
    new Chart(revenueCtx, {
        type: 'line',
        data: {
            labels: chartData.labels,
            datasets: [{
                label: 'Doanh thu',
                data: chartData.datasets.revenue,
                borderColor: '#0073aa',
                backgroundColor: 'rgba(0, 115, 170, 0.1)',
                fill: true,
                tension: 0.4
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return value.toLocaleString() + ' đ';
                        }
                    }
                }
            }
        }
    });

    // ========== HELPER FUNCTIONS ==========

    // Helper function to format currency
    function formatCurrency(value) {
        return new Intl.NumberFormat('vi-VN').format(value) + ' đ';
    }

    // Helper function to format number
    function formatNumber(value) {
        return new Intl.NumberFormat('vi-VN').format(value);
    }

    // Helper function to format date for display
    function formatDateDisplay(dateStr) {
        var d = new Date(dateStr);
        return d.getDate().toString().padStart(2, '0') + '/' +
               (d.getMonth() + 1).toString().padStart(2, '0') + '/' +
               d.getFullYear();
    }

    // Get today's date in YYYY-MM-DD format
    function getToday() {
        return new Date().toISOString().split('T')[0];
    }

    // ========== CHILD SHOP NAVIGATION ==========

    // View child detail - navigate to child shop dashboard
    $(document).on('click', '.tgs-view-child-detail', function(e) {
        e.preventDefault();
        var blogId = $(this).data('blog-id');

        // Get admin URL for child shop
        var adminUrl = '<?php echo admin_url('admin.php?page=tgs-sync-roll-up'); ?>';
        adminUrl = adminUrl.replace(/\/wp-admin\//, '/wp-admin/network/admin.php?blog=' + blogId + '&page=tgs-sync-roll-up');

        // Navigate to child shop dashboard
        window.location.href = adminUrl;
    });
});
</script>
