<?php
/**
 * Settings Page View
 *
 * @package TGS_Sync_Roll_Up
 */

if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap tgs-sync-settings">
    <h1><?php esc_html_e('TGS Sync Roll Up - Cài đặt', 'tgs-sync-roll-up'); ?></h1>

    <!-- Form riêng cho Parent Shop -->
    <form id="tgs-parent-form" method="post">
        <div class="tgs-panel">
            <div class="tgs-panel-header">
                <h2><?php esc_html_e('Cấu hình Shop Cha (Parent Shops)', 'tgs-sync-roll-up'); ?></h2>
            </div>

            <p class="description" style="margin-top: 0;">
                <?php esc_html_e('Chọn shop cha để đồng bộ dữ liệu roll_up. Shop cha sẽ nhận dữ liệu tổng hợp từ shop này.', 'tgs-sync-roll-up'); ?>
            </p>

            <?php
            // Lấy trạng thái approval
            $approval_status = $config->approval_status ?? null;
            $is_pending = ($approval_status === 'pending');
            $is_approved = ($approval_status === 'approved');
            $has_parent_and_approved = (!empty($parent_blog_id) && $is_approved);
            ?>

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="parent_blog_id"><?php esc_html_e('Shop cha', 'tgs-sync-roll-up'); ?></label>
                    </th>
                    <td>
                        <?php if (!empty($all_blogs)): ?>
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <select name="parent_blog_id" id="parent_blog_id" class="tgs-select2" style="width: 100%; max-width: 400px;" <?php disabled($is_pending || $has_parent_and_approved); ?>>
                                    <option value=""><?php esc_html_e('-- Không có shop cha --', 'tgs-sync-roll-up'); ?></option>
                                    <?php foreach ($all_blogs as $blog): ?>
                                        <?php if ($blog->blog_id != $blog_id): // Không cho chọn chính mình ?>
                                            <option value="<?php echo esc_attr($blog->blog_id); ?>"
                                                    <?php selected($parent_blog_id ?? 0, $blog->blog_id); ?>>
                                                <?php echo esc_html(TGS_Admin_Page::get_blog_name($blog->blog_id)); ?>
                                                (ID: <?php echo esc_html($blog->blog_id); ?>)
                                            </option>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </select>

                                <?php if ($has_parent_and_approved): ?>
                                    <!-- Đã có cha và đã approved: không hiển thị nút -->
                                    <p class="description" style="color: #856404; margin: 0;">
                                        <span class="dashicons dashicons-lock" style="vertical-align: middle;"></span>
                                        <?php esc_html_e('Shop cha đã được cấu hình và không thể thay đổi.', 'tgs-sync-roll-up'); ?>
                                    </p>
                                <?php elseif ($is_pending): ?>
                                    <!-- Đang pending: hiển thị nút Hủy Yêu Cầu màu đỏ -->
                                    <div class="tgs-parent-actions">
                                        <span id="tgs-save-parent-message" class="tgs-message"></span>
                                        <span class="spinner" id="tgs-parent-spinner"></span>
                                        <button type="button" class="button button-danger" id="tgs-cancel-parent-btn">
                                            <span class="dashicons dashicons-no" style="line-height: 1.4;"></span>
                                            <?php esc_html_e('Hủy Yêu Cầu', 'tgs-sync-roll-up'); ?>
                                        </button>
                                    </div>
                                <?php else: ?>
                                    <!-- Chưa có cha hoặc chưa pending: hiển thị nút Yêu Cầu -->
                                    <div class="tgs-parent-actions">
                                        <span id="tgs-save-parent-message" class="tgs-message"></span>
                                        <span class="spinner" id="tgs-parent-spinner"></span>
                                        <button type="submit" class="button button-primary" id="tgs-request-parent-btn">
                                            <span class="dashicons dashicons-admin-multisite" style="line-height: 1.4;"></span>
                                            <?php esc_html_e('Yêu Cầu', 'tgs-sync-roll-up'); ?>
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <?php if ($is_pending): ?>
                                <p class="description" style="color: #d63638; margin-top: 8px;">
                                    <span class="dashicons dashicons-clock" style="vertical-align: middle;"></span>
                                    <?php esc_html_e('Yêu cầu đang chờ shop cha xác nhận.', 'tgs-sync-roll-up'); ?>
                                </p>
                            <?php endif; ?>

                            <p id="tgs-parent-validation-warning" class="tgs-validation-warning" style="display: none;">
                                <span class="dashicons dashicons-warning"></span>
                                <span class="warning-text"></span>
                            </p>
                        <?php else: ?>
                            <p><?php esc_html_e('Không tìm thấy blog nào khác trong mạng.', 'tgs-sync-roll-up'); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
        </div>
    </form>

    <!-- Hierarchy Tree Panel -->
    <div class="tgs-panel tgs-hierarchy-panel">
        <h2>
            <span class="dashicons dashicons-networking"></span>
            <?php esc_html_e('Sơ đồ phân cấp Shop', 'tgs-sync-roll-up'); ?>
        </h2>
        <p class="description">
            <?php esc_html_e('Sơ đồ hiển thị quan hệ cha-con giữa các shop. Mũi tên chỉ hướng dữ liệu được đẩy lên (từ con → cha).', 'tgs-sync-roll-up'); ?>
        </p>

            <div class="tgs-hierarchy-tree">
                <?php
                // Helper function để render một node và children của nó
                function tgs_render_tree_node($node_id, $hierarchy_tree, $current_blog_id, $rendered = array(), $depth = 0) {
                    // Tránh vòng lặp vô hạn
                    if (in_array($node_id, $rendered) || $depth > 10) {
                        return '';
                    }
                    $rendered[] = $node_id;

                    $blog_names = $hierarchy_tree['blog_names'];
                    $children = $hierarchy_tree['children'];
                    $hierarchy = $hierarchy_tree['hierarchy'];

                    $is_current = ($node_id == $current_blog_id);
                    $node_name = isset($blog_names[$node_id]) ? $blog_names[$node_id] : "Blog #{$node_id}";
                    $parent_id = isset($hierarchy[$node_id]) ? $hierarchy[$node_id] : null;
                    $child_ids = isset($children[$node_id]) ? $children[$node_id] : array();

                    $class = 'tgs-tree-node';
                    if ($is_current) {
                        $class .= ' tgs-tree-node-current';
                    }
                    if (!empty($parent_id)) {
                        $class .= ' tgs-tree-node-has-parent';
                    }
                    if (!empty($child_ids)) {
                        $class .= ' tgs-tree-node-has-children';
                    }

                    $output = '<div class="' . esc_attr($class) . '" data-blog-id="' . esc_attr($node_id) . '">';
                    $output .= '<div class="tgs-tree-node-content">';

                    // Icon
                    if (empty($parent_id)) {
                        $output .= '<span class="dashicons dashicons-admin-multisite tgs-tree-icon tgs-tree-icon-root"></span>';
                    } elseif (empty($child_ids)) {
                        $output .= '<span class="dashicons dashicons-store tgs-tree-icon tgs-tree-icon-leaf"></span>';
                    } else {
                        $output .= '<span class="dashicons dashicons-building tgs-tree-icon"></span>';
                    }

                    // Node name
                    $output .= '<span class="tgs-tree-node-name">' . esc_html($node_name) . '</span>';
                    $output .= '<span class="tgs-tree-node-id">(ID: ' . esc_html($node_id) . ')</span>';

                    // Badge for current blog
                    if ($is_current) {
                        $output .= '<span class="tgs-tree-badge tgs-tree-badge-current">' . esc_html__('Đang xem', 'tgs-sync-roll-up') . '</span>';
                    }

                    // Parent indicator
                    if (!empty($parent_id) && !$is_current) {
                        $parent_name = isset($blog_names[$parent_id]) ? $blog_names[$parent_id] : "Blog #{$parent_id}";
                        $output .= '<span class="tgs-tree-arrow">→</span>';
                        $output .= '<span class="tgs-tree-parents">' . esc_html($parent_name) . '</span>';
                    }

                    $output .= '</div>'; // .tgs-tree-node-content

                    // Render children
                    if (!empty($child_ids)) {
                        $output .= '<div class="tgs-tree-children">';
                        foreach ($child_ids as $child_id) {
                            $output .= tgs_render_tree_node($child_id, $hierarchy_tree, $current_blog_id, $rendered, $depth + 1);
                        }
                        $output .= '</div>';
                    }

                    $output .= '</div>'; // .tgs-tree-node

                    return $output;
                }

                // Render từ root nodes
                if (!empty($hierarchy_tree['root_nodes'])):
                    echo '<div class="tgs-tree-container">';
                    foreach ($hierarchy_tree['root_nodes'] as $root_id):
                        echo tgs_render_tree_node($root_id, $hierarchy_tree, $blog_id);
                    endforeach;
                    echo '</div>';
                else:
                ?>
                    <p class="tgs-no-hierarchy"><?php esc_html_e('Chưa có cấu hình phân cấp nào.', 'tgs-sync-roll-up'); ?></p>
                <?php endif; ?>
            </div>

            <div class="tgs-hierarchy-legend">
                <h4><?php esc_html_e('Chú thích:', 'tgs-sync-roll-up'); ?></h4>
                <ul>
                    <li><span class="dashicons dashicons-admin-multisite tgs-tree-icon-root"></span> <?php esc_html_e('Shop gốc (không có cha)', 'tgs-sync-roll-up'); ?></li>
                    <li><span class="dashicons dashicons-building"></span> <?php esc_html_e('Shop trung gian (có cả cha và con)', 'tgs-sync-roll-up'); ?></li>
                    <li><span class="dashicons dashicons-store tgs-tree-icon-leaf"></span> <?php esc_html_e('Shop lá (không có con)', 'tgs-sync-roll-up'); ?></li>
                    <li><span class="tgs-tree-badge tgs-tree-badge-current"><?php esc_html_e('Đang xem', 'tgs-sync-roll-up'); ?></span> <?php esc_html_e('Shop hiện tại', 'tgs-sync-roll-up'); ?></li>
                </ul>
            </div>
    </div>

    <!-- Form riêng cho Sync Settings -->
    <form id="tgs-settings-form" method="post">
        <div class="tgs-panel">
            <div class="tgs-panel-header">
                <h2><?php esc_html_e('Cấu hình Sync', 'tgs-sync-roll-up'); ?></h2>
                <div class="tgs-panel-actions">
                    <span id="tgs-save-message" class="tgs-message"></span>
                    <span class="spinner"></span>
                    <button type="submit" class="button button-primary" id="tgs-save-settings-btn">
                        <span class="dashicons dashicons-saved" style="line-height: 1.4;"></span>
                        <?php esc_html_e('Lưu cài đặt', 'tgs-sync-roll-up'); ?>
                    </button>
                </div>
            </div>

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="sync_enabled"><?php esc_html_e('Kích hoạt Sync', 'tgs-sync-roll-up'); ?></label>
                    </th>
                    <td>
                        <label class="tgs-switch">
                            <input type="checkbox" name="sync_enabled" id="sync_enabled" value="1" 
                                   <?php checked($config->sync_enabled ?? 0, 1); ?>>
                            <span class="tgs-slider"></span>
                        </label>
                        <p class="description">
                            <?php esc_html_e('Bật/tắt việc tự động sync dữ liệu roll_up.', 'tgs-sync-roll-up'); ?>
                        </p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="sync_frequency"><?php esc_html_e('Tần suất Sync', 'tgs-sync-roll-up'); ?></label>
                    </th>
                    <td>
                        <select name="sync_frequency" id="sync_frequency">
                            <option value="every_fifteen_minutes" <?php selected($config->sync_frequency ?? '', 'every_fifteen_minutes'); ?>>
                                <?php esc_html_e('Mỗi 15 phút', 'tgs-sync-roll-up'); ?>
                            </option>
                            <option value="every_thirty_minutes" <?php selected($config->sync_frequency ?? '', 'every_thirty_minutes'); ?>>
                                <?php esc_html_e('Mỗi 30 phút', 'tgs-sync-roll-up'); ?>
                            </option>
                            <option value="hourly" <?php selected($config->sync_frequency ?? 'hourly', 'hourly'); ?>>
                                <?php esc_html_e('Mỗi giờ', 'tgs-sync-roll-up'); ?>
                            </option>
                            <option value="every_two_hours" <?php selected($config->sync_frequency ?? '', 'every_two_hours'); ?>>
                                <?php esc_html_e('Mỗi 2 giờ', 'tgs-sync-roll-up'); ?>
                            </option>
                            <option value="every_four_hours" <?php selected($config->sync_frequency ?? '', 'every_four_hours'); ?>>
                                <?php esc_html_e('Mỗi 4 giờ', 'tgs-sync-roll-up'); ?>
                            </option>
                            <option value="every_six_hours" <?php selected($config->sync_frequency ?? '', 'every_six_hours'); ?>>
                                <?php esc_html_e('Mỗi 6 giờ', 'tgs-sync-roll-up'); ?>
                            </option>
                            <option value="twicedaily" <?php selected($config->sync_frequency ?? '', 'twicedaily'); ?>>
                                <?php esc_html_e('2 lần/ngày', 'tgs-sync-roll-up'); ?>
                            </option>
                            <option value="daily" <?php selected($config->sync_frequency ?? '', 'daily'); ?>>
                                <?php esc_html_e('Hàng ngày', 'tgs-sync-roll-up'); ?>
                            </option>
                        </select>
                        <p class="description">
                            <?php esc_html_e('Tần suất chạy cron job để sync dữ liệu.', 'tgs-sync-roll-up'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <div class="tgs-panel">
            <h2><?php esc_html_e('Thông tin Cron', 'tgs-sync-roll-up'); ?></h2>
            <table class="form-table">
                <tr>
                    <th><?php esc_html_e('Sync tiếp theo', 'tgs-sync-roll-up'); ?></th>
                    <td>
                        <code><?php echo esc_html($cron_info['sync']['next_run_formatted'] ?? 'Chưa lên lịch'); ?></code>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Cleanup tiếp theo', 'tgs-sync-roll-up'); ?></th>
                    <td>
                        <code><?php echo esc_html($cron_info['cleanup']['next_run_formatted'] ?? 'Chưa lên lịch'); ?></code>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Monthly tiếp theo', 'tgs-sync-roll-up'); ?></th>
                    <td>
                        <code><?php echo esc_html($cron_info['monthly']['next_run_formatted'] ?? 'Chưa lên lịch'); ?></code>
                    </td>
                </tr>
            </table>
        </div>

        <div class="tgs-panel">
            <h2><?php esc_html_e('Thông tin hiện tại', 'tgs-sync-roll-up'); ?></h2>
            <table class="form-table">
                <tr>
                    <th><?php esc_html_e('Blog ID hiện tại', 'tgs-sync-roll-up'); ?></th>
                    <td><code><?php echo esc_html($blog_id); ?></code></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Tên shop', 'tgs-sync-roll-up'); ?></th>
                    <td><code><?php echo esc_html(get_bloginfo('name')); ?></code></td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Shop cha đã cấu hình', 'tgs-sync-roll-up'); ?></th>
                    <td>
                        <?php if (!empty($parent_blog_id)): ?>
                            <code><?php echo esc_html(TGS_Admin_Page::get_blog_name($parent_blog_id)); ?></code>
                            (ID: <?php echo esc_html($parent_blog_id); ?>)
                        <?php else: ?>
                            <em><?php esc_html_e('Chưa cấu hình', 'tgs-sync-roll-up'); ?></em>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th><?php esc_html_e('Lần sync cuối', 'tgs-sync-roll-up'); ?></th>
                    <td>
                        <code><?php echo esc_html(TGS_Admin_Page::format_datetime($config->last_sync_at ?? null)); ?></code>
                    </td>
                </tr>
            </table>
        </div>
    </form>
</div>

<style>
/* Panel Header with Actions */
.tgs-panel-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
    padding-bottom: 15px;
    border-bottom: 1px solid #ddd;
}

.tgs-panel-header h2 {
    margin: 0;
    padding: 0;
    line-height: 1.4;
}

.tgs-panel-actions {
    display: flex;
    align-items: center;
    gap: 10px;
}

.tgs-panel-actions .button .dashicons {
    line-height: inherit;
    vertical-align: middle;
    margin-top: -2px;
}

.tgs-panel-actions .spinner {
    float: none;
    margin: 0;
}

.tgs-panel-actions .tgs-message {
    white-space: nowrap;
    font-size: 13px;
}

.tgs-panel-actions .tgs-message.success {
    color: #00a32a;
}

.tgs-panel-actions .tgs-message.error {
    color: #d63638;
}

/* Toggle Switch */
.tgs-switch {
    position: relative;
    display: inline-block;
    width: 60px;
    height: 34px;
}

.tgs-switch input {
    opacity: 0;
    width: 0;
    height: 0;
}

.tgs-slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: #ccc;
    transition: .4s;
    border-radius: 34px;
}

.tgs-slider:before {
    position: absolute;
    content: "";
    height: 26px;
    width: 26px;
    left: 4px;
    bottom: 4px;
    background-color: white;
    transition: .4s;
    border-radius: 50%;
}

input:checked + .tgs-slider {
    background-color: #0073aa;
}

input:checked + .tgs-slider:before {
    transform: translateX(26px);
}

/* Panel Danger */
.tgs-panel-danger {
    border-left-color: #dc3545;
}

.tgs-panel-danger h2 {
    color: #dc3545;
}

/* Validation Warning */
.tgs-validation-warning {
    color: #856404;
    background-color: #fff3cd;
    border: 1px solid #ffeeba;
    padding: 8px 12px;
    border-radius: 4px;
    margin-top: 8px;
}

.tgs-validation-warning .dashicons {
    color: #856404;
    margin-right: 5px;
    vertical-align: middle;
}

.tgs-validation-warning .warning-text {
    vertical-align: middle;
}

/* Disabled option styling for select */
#parent_blog_id option:disabled {
    color: #999;
    background-color: #f5f5f5;
    font-style: italic;
}

#parent_blog_id option.tgs-ancestor-disabled {
    color: #999 !important;
    background-color: #ffe6e6 !important;
}

/* Hierarchy Tree Styles */
.tgs-hierarchy-panel h2 .dashicons {
    margin-right: 5px;
    vertical-align: middle;
}

.tgs-hierarchy-tree {
    background: #f9f9f9;
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 15px;
    margin: 15px 0;
    overflow-x: auto;
}

.tgs-tree-container {
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.tgs-tree-node {
    position: relative;
    padding-left: 0;
}

.tgs-tree-node-content {
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 8px 12px;
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 4px;
    margin-bottom: 5px;
    transition: all 0.2s ease;
}

.tgs-tree-node-content:hover {
    border-color: #0073aa;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.tgs-tree-node-current > .tgs-tree-node-content {
    background: #e6f3ff;
    border-color: #0073aa;
    border-width: 2px;
}

.tgs-tree-icon {
    font-size: 18px;
    width: 20px;
    height: 20px;
    color: #666;
}

.tgs-tree-icon-root {
    color: #d63638;
}

.tgs-tree-icon-leaf {
    color: #00a32a;
}

.tgs-tree-node-name {
    font-weight: 600;
    color: #1e1e1e;
}

.tgs-tree-node-id {
    font-size: 12px;
    color: #666;
}

.tgs-tree-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 3px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
}

.tgs-tree-badge-current {
    background: #0073aa;
    color: #fff;
}

.tgs-tree-arrow {
    color: #0073aa;
    font-weight: bold;
    font-size: 16px;
}

.tgs-tree-parents {
    font-size: 12px;
    color: #0073aa;
    font-style: italic;
}

.tgs-tree-children {
    margin-left: 30px;
    padding-left: 15px;
    border-left: 2px dashed #ddd;
}

.tgs-hierarchy-legend {
    margin-top: 15px;
    padding: 10px 15px;
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 4px;
}

.tgs-hierarchy-legend h4 {
    margin: 0 0 10px 0;
    font-size: 13px;
    color: #666;
}

.tgs-hierarchy-legend ul {
    margin: 0;
    padding: 0;
    list-style: none;
    display: flex;
    flex-wrap: wrap;
    gap: 15px;
}

.tgs-hierarchy-legend li {
    display: flex;
    align-items: center;
    gap: 5px;
    font-size: 12px;
    color: #666;
}

.tgs-hierarchy-legend .dashicons {
    font-size: 16px;
    width: 16px;
    height: 16px;
}

.tgs-no-hierarchy {
    color: #666;
    font-style: italic;
    margin: 0;
}

/* Button Danger - Red Button Style */
.button-danger {
    background: #d63638 !important;
    border-color: #d63638 !important;
    color: #fff !important;
}

.button-danger:hover {
    background: #ba2d2f !important;
    border-color: #ba2d2f !important;
}

.button-danger:focus {
    box-shadow: 0 0 0 1px #fff, 0 0 0 3px #d63638 !important;
}

.tgs-parent-actions {
    display: flex;
    align-items: center;
    gap: 10px;
}

.tgs-parent-actions .spinner {
    float: none;
    margin: 0;
}

.tgs-parent-actions .tgs-message {
    white-space: nowrap;
    font-size: 13px;
}

.tgs-parent-actions .tgs-message.success {
    color: #00a32a;
}

.tgs-parent-actions .tgs-message.error {
    color: #d63638;
}
</style>
