<?php
/**
 * Inventory Roll-up View
 *
 * @package TGS_Sync_Roll_Up
 */

if (!defined('ABSPATH')) {
    exit;
}

// Lấy date từ query string hoặc mặc định là hôm nay
$selected_date = isset($_GET['inventory_date']) ? sanitize_text_field($_GET['inventory_date']) : current_time('Y-m-d');
$blog_id = get_current_blog_id();

// Khởi tạo inventory calculator
$inventory_calculator = new TGS_Inventory_Roll_Up_Calculator();

// Lấy inventory summary trong 7 ngày
$end_date = $selected_date;
$start_date = date('Y-m-d', strtotime($selected_date . ' -6 days'));
$inventory_summary = $inventory_calculator->get_inventory_summary($blog_id, $start_date, $end_date);
?>

<div class="wrap tgs-sync-inventory">
    <h1><?php esc_html_e('Tồn Kho (Inventory Roll-up)', 'tgs-sync-roll-up'); ?></h1>

    <!-- Date Filter -->
    <div class="tgs-panel">
        <h2><?php esc_html_e('Chọn Ngày', 'tgs-sync-roll-up'); ?></h2>
        <form method="get" action="">
            <input type="hidden" name="page" value="tgs-sync-roll-up-inventory">
            <div style="display: flex; gap: 10px; align-items: center;">
                <label for="inventory_date"><?php esc_html_e('Ngày:', 'tgs-sync-roll-up'); ?></label>
                <input type="date" id="inventory_date" name="inventory_date" value="<?php echo esc_attr($selected_date); ?>" required>
                <button type="submit" class="button button-primary">
                    <span class="dashicons dashicons-search"></span>
                    <?php esc_html_e('Xem', 'tgs-sync-roll-up'); ?>
                </button>
            </div>
        </form>
    </div>

    <!-- Inventory Summary Chart -->
    <div class="tgs-charts-section">
        <div class="tgs-chart-container">
            <h2><?php esc_html_e('Tổng giá trị tồn kho 7 ngày gần đây', 'tgs-sync-roll-up'); ?></h2>
            <canvas id="tgs-inventory-chart"></canvas>
        </div>
    </div>

    <!-- Current Inventory Table -->
    <div class="tgs-panel">
        <h2><?php esc_html_e('Tồn Kho Ngày: ' . date('d/m/Y', strtotime($selected_date)), 'tgs-sync-roll-up'); ?></h2>

        <div id="tgs-inventory-table-container">
            <p><?php esc_html_e('Đang tải...', 'tgs-sync-roll-up'); ?></p>
        </div>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Prepare chart data
    var chartData = <?php echo json_encode($inventory_summary); ?>;
    var labels = [];
    var quantities = [];
    var values = [];

    chartData.forEach(function(item) {
        var date = new Date(item.roll_up_date);
        labels.push((date.getDate() < 10 ? '0' : '') + date.getDate() + '/' +
                   ((date.getMonth() + 1) < 10 ? '0' : '') + (date.getMonth() + 1));
        quantities.push(parseFloat(item.total_qty));
        values.push(parseFloat(item.total_value));
    });

    // Inventory value chart
    var inventoryCtx = document.getElementById('tgs-inventory-chart').getContext('2d');
    new Chart(inventoryCtx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: [{
                label: 'Giá trị tồn kho',
                data: values,
                borderColor: '#00a32a',
                backgroundColor: 'rgba(0, 163, 42, 0.1)',
                fill: true,
                tension: 0.4,
                yAxisID: 'y'
            }, {
                label: 'Số lượng tồn kho',
                data: quantities,
                borderColor: '#0073aa',
                backgroundColor: 'rgba(0, 115, 170, 0.1)',
                fill: true,
                tension: 0.4,
                yAxisID: 'y1'
            }]
        },
        options: {
            responsive: true,
            interaction: {
                mode: 'index',
                intersect: false,
            },
            scales: {
                y: {
                    type: 'linear',
                    display: true,
                    position: 'left',
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return value.toLocaleString() + ' đ';
                        }
                    }
                },
                y1: {
                    type: 'linear',
                    display: true,
                    position: 'right',
                    beginAtZero: true,
                    grid: {
                        drawOnChartArea: false,
                    },
                    ticks: {
                        callback: function(value) {
                            return value.toLocaleString();
                        }
                    }
                }
            }
        }
    });

    // Load inventory table
    function loadInventoryTable() {
        var selectedDate = '<?php echo esc_js($selected_date); ?>';

        $.post(tgsSyncRollUp.ajaxUrl, {
            action: 'tgs_get_inventory_data',
            nonce: tgsSyncRollUp.nonce,
            date: selectedDate
        })
        .done(function(response) {
            if (response.success && response.data.inventory.length > 0) {
                var html = '<table class="wp-list-table widefat fixed striped">';
                html += '<thead><tr>';
                html += '<th>STT</th>';
                html += '<th>Sản phẩm ID</th>';
                html += '<th>Số lượng tồn</th>';
                html += '<th>Giá trị tồn</th>';
                html += '</tr></thead>';
                html += '<tbody>';

                response.data.inventory.forEach(function(item, index) {
                    html += '<tr>';
                    html += '<td>' + (index + 1) + '</td>';
                    html += '<td><strong>' + item.local_product_name_id + '</strong></td>';
                    html += '<td>' + parseFloat(item.inventory_qty).toLocaleString() + '</td>';
                    html += '<td>' + parseFloat(item.inventory_value).toLocaleString() + ' đ</td>';
                    html += '</tr>';
                });

                html += '</tbody></table>';

                $('#tgs-inventory-table-container').html(html);
            } else {
                $('#tgs-inventory-table-container').html('<p>Không có dữ liệu tồn kho cho ngày này.</p>');
            }
        })
        .fail(function() {
            $('#tgs-inventory-table-container').html('<p style="color: red;">Lỗi khi tải dữ liệu.</p>');
        });
    }

    // Load table on page load
    loadInventoryTable();
});
</script>
