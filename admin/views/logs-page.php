<?php
/**
 * Logs Page View
 *
 * @package TGS_Sync_Roll_Up
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap tgs-sync-logs">
    <h1><?php esc_html_e('TGS Sync Roll Up - Logs', 'tgs-sync-roll-up'); ?></h1>

    <!-- Tabs -->
    <h2 class="nav-tab-wrapper">
        <a href="#sync-logs" class="nav-tab nav-tab-active" data-tab="sync-logs">
            <?php esc_html_e('Sync Logs', 'tgs-sync-roll-up'); ?>
        </a>
        <a href="#cron-logs" class="nav-tab" data-tab="cron-logs">
            <?php esc_html_e('Cron Logs', 'tgs-sync-roll-up'); ?>
        </a>
    </h2>

    <!-- Sync Logs Tab -->
    <div id="sync-logs" class="tgs-tab-content active">
        <div class="tgs-panel">
            <h2><?php esc_html_e('Sync Logs', 'tgs-sync-roll-up'); ?></h2>
            <p class="description">
                <?php esc_html_e('Lịch sử các lần sync dữ liệu roll_up lên shop cha.', 'tgs-sync-roll-up'); ?>
            </p>

            <?php if (!empty($sync_logs)): ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 150px;"><?php esc_html_e('Thời gian', 'tgs-sync-roll-up'); ?></th>
                            <th style="width: 100px;"><?php esc_html_e('Ngày sync', 'tgs-sync-roll-up'); ?></th>
                            <th style="width: 100px;"><?php esc_html_e('Thành công', 'tgs-sync-roll-up'); ?></th>
                            <th style="width: 100px;"><?php esc_html_e('Thất bại', 'tgs-sync-roll-up'); ?></th>
                            <th><?php esc_html_e('Chi tiết', 'tgs-sync-roll-up'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($sync_logs as $log): ?>
                            <tr>
                                <td><?php echo esc_html(TGS_Admin_Page::format_datetime($log['timestamp'])); ?></td>
                                <td><?php echo esc_html($log['date'] ?? '-'); ?></td>
                                <td>
                                    <span class="tgs-badge tgs-badge-success">
                                        <?php echo esc_html($log['success_count'] ?? 0); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (($log['failed_count'] ?? 0) > 0): ?>
                                        <span class="tgs-badge tgs-badge-danger">
                                            <?php echo esc_html($log['failed_count']); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="tgs-badge tgs-badge-success">0</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if (!empty($log['details'])): ?>
                                        <details>
                                            <summary><?php esc_html_e('Xem chi tiết', 'tgs-sync-roll-up'); ?></summary>
                                            <pre class="tgs-log-details"><?php echo esc_html(json_encode($log['details'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
                                        </details>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p><?php esc_html_e('Chưa có sync log nào.', 'tgs-sync-roll-up'); ?></p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Cron Logs Tab -->
    <div id="cron-logs" class="tgs-tab-content" style="display: none;">
        <div class="tgs-panel">
            <h2><?php esc_html_e('Cron Logs', 'tgs-sync-roll-up'); ?></h2>
            <p class="description">
                <?php esc_html_e('Lịch sử các lần chạy cron job.', 'tgs-sync-roll-up'); ?>
            </p>

            <?php if (!empty($cron_logs)): ?>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 150px;"><?php esc_html_e('Thời gian', 'tgs-sync-roll-up'); ?></th>
                            <th style="width: 100px;"><?php esc_html_e('Loại', 'tgs-sync-roll-up'); ?></th>
                            <th style="width: 100px;"><?php esc_html_e('Trạng thái', 'tgs-sync-roll-up'); ?></th>
                            <th><?php esc_html_e('Chi tiết', 'tgs-sync-roll-up'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($cron_logs as $log): ?>
                            <tr>
                                <td><?php echo esc_html(TGS_Admin_Page::format_datetime($log['timestamp'])); ?></td>
                                <td>
                                    <span class="tgs-badge tgs-badge-<?php echo esc_attr($log['type']); ?>">
                                        <?php echo esc_html(ucfirst($log['type'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php
                                    $status_class = '';
                                    switch ($log['status']) {
                                        case 'success':
                                            $status_class = 'tgs-badge-success';
                                            break;
                                        case 'error':
                                            $status_class = 'tgs-badge-danger';
                                            break;
                                        case 'start':
                                            $status_class = 'tgs-badge-info';
                                            break;
                                        case 'skipped':
                                            $status_class = 'tgs-badge-warning';
                                            break;
                                        default:
                                            $status_class = 'tgs-badge-secondary';
                                    }
                                    ?>
                                    <span class="tgs-badge <?php echo esc_attr($status_class); ?>">
                                        <?php echo esc_html(ucfirst($log['status'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($log['data'])): ?>
                                        <details>
                                            <summary><?php esc_html_e('Xem chi tiết', 'tgs-sync-roll-up'); ?></summary>
                                            <pre class="tgs-log-details"><?php 
                                                if (is_string($log['data'])) {
                                                    echo esc_html($log['data']);
                                                } else {
                                                    echo esc_html(json_encode($log['data'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                                                }
                                            ?></pre>
                                        </details>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <p><?php esc_html_e('Chưa có cron log nào.', 'tgs-sync-roll-up'); ?></p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Clear Logs -->
    <div class="tgs-panel">
        <h2><?php esc_html_e('Quản lý Logs', 'tgs-sync-roll-up'); ?></h2>
        <p>
            <button type="button" class="button" id="tgs-clear-logs-btn">
                <?php esc_html_e('Xóa tất cả logs', 'tgs-sync-roll-up'); ?>
            </button>
            <span class="description">
                <?php esc_html_e('Logs cũ hơn 30 ngày sẽ tự động bị xóa bởi cron job cleanup.', 'tgs-sync-roll-up'); ?>
            </span>
        </p>
    </div>
</div>

<script>
jQuery(document).ready(function($) {
    // Tab switching
    $('.nav-tab').on('click', function(e) {
        e.preventDefault();
        var tab = $(this).data('tab');
        
        $('.nav-tab').removeClass('nav-tab-active');
        $(this).addClass('nav-tab-active');
        
        $('.tgs-tab-content').hide();
        $('#' + tab).show();
    });

    // Clear logs
    $('#tgs-clear-logs-btn').on('click', function() {
        if (!confirm('<?php esc_html_e('Bạn có chắc muốn xóa tất cả logs?', 'tgs-sync-roll-up'); ?>')) {
            return;
        }
        // TODO: Implement clear logs AJAX
        alert('<?php esc_html_e('Tính năng đang phát triển', 'tgs-sync-roll-up'); ?>');
    });
});
</script>

<style>
.tgs-tab-content {
    margin-top: 20px;
}

.tgs-log-details {
    background: #f5f5f5;
    padding: 10px;
    border-radius: 4px;
    font-size: 12px;
    max-height: 300px;
    overflow: auto;
    margin-top: 10px;
}

details summary {
    cursor: pointer;
    color: #0073aa;
}

details summary:hover {
    text-decoration: underline;
}

.tgs-badge-sync,
.tgs-badge-info {
    background-color: #0073aa;
}

.tgs-badge-cleanup {
    background-color: #826eb4;
}

.tgs-badge-monthly {
    background-color: #00a0d2;
}

.tgs-badge-warning {
    background-color: #ffb900;
    color: #000;
}

.tgs-badge-secondary {
    background-color: #6c757d;
}
</style>
