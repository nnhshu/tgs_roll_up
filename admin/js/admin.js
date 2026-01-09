/**
 * TGS Sync Roll Up - Admin JavaScript
 *
 * @package TGS_Sync_Roll_Up
 */

(function($) {
    'use strict';

    // Namespace
    var TGSSyncAdmin = {
        
        /**
         * Initialize
         */
        init: function() {
            this.bindEvents();
            this.initModals();
        },

        /**
         * Bind events
         */
        bindEvents: function() {
            // Settings form submit
            $('#tgs-settings-form').on('submit', this.handleSettingsSubmit.bind(this));

            // Manual sync button
            $('#tgs-manual-sync-btn, #tgs-force-sync-btn').on('click', this.handleManualSync.bind(this));

            // Rebuild button
            $('#tgs-rebuild-btn').on('click', this.showRebuildModal.bind(this));

            // Rebuild all button
            $('#tgs-rebuild-all-btn').on('click', this.handleRebuildAll.bind(this));

            // Rebuild form submit
            $('#tgs-rebuild-form').on('submit', this.handleRebuildSubmit.bind(this));

            // View log details
            $('.tgs-view-log-details').on('click', this.handleViewLogDetails.bind(this));

            // Parent shop selection validation
            $('#parent_blog_ids').on('change', this.handleParentSelectionChange.bind(this));

            // Initialize parent validation on page load
            this.initParentValidation();
        },

        /**
         * Initialize modals
         */
        initModals: function() {
            // Close modal on click outside or close button
            $('.tgs-modal').on('click', function(e) {
                if ($(e.target).hasClass('tgs-modal') || $(e.target).hasClass('tgs-modal-close')) {
                    $(this).hide();
                }
            });

            // Close modal on escape
            $(document).on('keydown', function(e) {
                if (e.key === 'Escape') {
                    $('.tgs-modal').hide();
                }
            });
        },

        /**
         * Handle settings form submit
         */
        handleSettingsSubmit: function(e) {
            e.preventDefault();

            var $form = $(e.target);
            var $button = $form.find('#tgs-save-settings-btn');
            var $spinner = $form.find('.spinner');
            var $message = $form.find('#tgs-save-message');

            // Show loading
            $button.prop('disabled', true);
            $spinner.addClass('is-active');
            $message.removeClass('success error').text('');

            // Gather form data
            var formData = {
                action: 'tgs_save_sync_settings',
                nonce: tgsSyncRollUp.nonce,
                parent_blog_ids: $form.find('[name="parent_blog_ids[]"]').val() || [],
                sync_enabled: $form.find('[name="sync_enabled"]').is(':checked') ? 1 : 0,
                sync_frequency: $form.find('[name="sync_frequency"]').val()
            };

            // Send AJAX request
            $.post(tgsSyncRollUp.ajaxUrl, formData)
                .done(function(response) {
                    if (response.success) {
                        $message.addClass('success').text(response.data.message);
                    } else {
                        $message.addClass('error').text(response.data.message || tgsSyncRollUp.i18n.error);
                    }
                })
                .fail(function() {
                    $message.addClass('error').text(tgsSyncRollUp.i18n.error);
                })
                .always(function() {
                    $button.prop('disabled', false);
                    $spinner.removeClass('is-active');
                });
        },

        /**
         * Handle manual sync
         */
        handleManualSync: function(e) {
            var $button = $(e.target);
            var originalText = $button.text();

            $button.prop('disabled', true).text(tgsSyncRollUp.i18n.syncing);

            $.post(tgsSyncRollUp.ajaxUrl, {
                action: 'tgs_manual_sync',
                nonce: tgsSyncRollUp.nonce,
                date: this.getCurrentDate()
            })
            .done(function(response) {
                if (response.success) {
                    alert(response.data.message);
                    // Reload page to show updated data
                    location.reload();
                } else {
                    alert(response.data.message || tgsSyncRollUp.i18n.error);
                }
            })
            .fail(function() {
                alert(tgsSyncRollUp.i18n.error);
            })
            .always(function() {
                $button.prop('disabled', false).text(originalText);
            });
        },

        /**
         * Show rebuild modal
         */
        showRebuildModal: function() {
            $('#tgs-rebuild-modal').show();
        },

        /**
         * Handle rebuild form submit
         */
        handleRebuildSubmit: function(e) {
            e.preventDefault();

            if (!confirm(tgsSyncRollUp.i18n.confirmRebuild)) {
                return;
            }

            var $form = $(e.target);
            var $button = $form.find('button[type="submit"]');
            var originalText = $button.text();

            $button.prop('disabled', true).text(tgsSyncRollUp.i18n.rebuilding);

            $.post(tgsSyncRollUp.ajaxUrl, {
                action: 'tgs_rebuild_rollup',
                nonce: tgsSyncRollUp.nonce,
                start_date: $form.find('[name="start_date"]').val(),
                end_date: $form.find('[name="end_date"]').val(),
                sync_to_parents: $form.find('[name="sync_to_parents"]').is(':checked') ? 1 : 0
            })
            .done(function(response) {
                if (response.success) {
                    alert(response.data.message);
                    $('#tgs-rebuild-modal').hide();
                    location.reload();
                } else {
                    alert(response.data.message || tgsSyncRollUp.i18n.error);
                }
            })
            .fail(function() {
                alert(tgsSyncRollUp.i18n.error);
            })
            .always(function() {
                $button.prop('disabled', false).text(originalText);
            });
        },

        /**
         * Handle rebuild all button
         */
        handleRebuildAll: function(e) {
            if (!confirm(tgsSyncRollUp.i18n.confirmRebuild)) {
                return;
            }

            var $button = $(e.target);
            var originalText = $button.text();
            var today = this.getCurrentDate();
            var firstOfMonth = today.substring(0, 8) + '01';

            $button.prop('disabled', true).text(tgsSyncRollUp.i18n.rebuilding);

            $.post(tgsSyncRollUp.ajaxUrl, {
                action: 'tgs_rebuild_rollup',
                nonce: tgsSyncRollUp.nonce,
                start_date: firstOfMonth,
                end_date: today,
                sync_to_parents: 1
            })
            .done(function(response) {
                if (response.success) {
                    alert(response.data.message);
                    location.reload();
                } else {
                    alert(response.data.message || tgsSyncRollUp.i18n.error);
                }
            })
            .fail(function() {
                alert(tgsSyncRollUp.i18n.error);
            })
            .always(function() {
                $button.prop('disabled', false).text(originalText);
            });
        },

        /**
         * Handle view log details
         */
        handleViewLogDetails: function(e) {
            var details = $(e.target).data('details');
            if (details) {
                console.log('Log Details:', details);
                alert(JSON.stringify(details, null, 2));
            }
        },

        /**
         * Initialize parent validation on page load
         */
        initParentValidation: function() {
            var $select = $('#parent_blog_ids');
            if ($select.length === 0) {
                return;
            }

            // Debug: log hierarchy data
            console.log('TGS Parent Validation - Hierarchy:', tgsSyncRollUp.shopHierarchy);
            console.log('TGS Parent Validation - Current Blog ID:', tgsSyncRollUp.currentBlogId);
            console.log('TGS Parent Validation - Selected Values:', $select.val());

            // Run validation for currently selected values
            this.validateParentSelection();
        },

        /**
         * Handle parent selection change
         */
        handleParentSelectionChange: function() {
            this.validateParentSelection();
        },

        /**
         * Validate parent selection using graph-based approach
         *
         * Logic chính:
         * - Xây dựng đồ thị quan hệ cha-con từ hierarchy
         * - Từ shop hiện tại, tìm tất cả các shop có thể đến được qua đường đi gián tiếp
         * - Shop X bị disable nếu: có thể đến X qua shop trung gian (A → B → X)
         *   VÀ X không phải là cha trực tiếp đã được cấu hình
         *
         * Ví dụ: hierarchy = {10: [2], 2: [1]}
         * - Shop 10 có cha = [2], Shop 2 có cha = [1]
         * - Đang ở Shop 10:
         *   + 2 là cha trực tiếp → KHÔNG disable
         *   + 1 có thể đến qua 2 (10 → 2 → 1) → DISABLE (phải qua trung gian 2)
         *
         * THÊM: Disable các con/cháu của shop hiện tại để tránh vòng lặp (cycle)
         */
        validateParentSelection: function() {
            var $select = $('#parent_blog_ids');
            var $warning = $('#tgs-parent-validation-warning');
            var hierarchy = tgsSyncRollUp.shopHierarchy || {};
            var currentBlogId = parseInt(tgsSyncRollUp.currentBlogId, 10);

            // Lấy danh sách cha trực tiếp của shop hiện tại (đã cấu hình)
            var directParents = (hierarchy[currentBlogId] || []).map(function(v) {
                return parseInt(v, 10);
            });

            console.log('=== TGS Graph Validation ===');
            console.log('Current Blog ID:', currentBlogId);
            console.log('Direct parents (configured):', directParents);
            console.log('Full hierarchy:', hierarchy);

            // Xây dựng graph và tìm các shop có thể đến được qua trung gian (ancestors)
            var reachableViaIntermediate = this.findReachableViaIntermediate(hierarchy, currentBlogId, directParents);

            // TÌM TẤT CẢ CON/CHÁU (descendants) của shop hiện tại để ngăn cycle
            var descendants = this.findAllDescendants(hierarchy, currentBlogId);

            console.log('Reachable via intermediate (should be disabled):', reachableViaIntermediate);
            console.log('Descendants (would create cycle):', descendants);

            // Reset all options first, then disable invalid ones
            $select.find('option').each(function() {
                var $option = $(this);
                var blogId = parseInt($option.val(), 10);

                // Skip if it's the current blog
                if (blogId === currentBlogId) {
                    return;
                }

                var originalText = $option.data('original-text') || $option.text();
                $option.data('original-text', originalText);

                // Case 1: Đây là con/cháu của shop hiện tại → DISABLE (tránh cycle)
                if (descendants.indexOf(blogId) !== -1) {
                    $option.prop('disabled', true);
                    $option.addClass('tgs-descendant-disabled');
                    $option.removeClass('tgs-ancestor-disabled');
                    if (originalText.indexOf('[shop con') === -1) {
                        $option.text(originalText + ' [shop con - không thể chọn]');
                    }
                    return;
                }

                // Case 2: Đây là tổ tiên có thể đến qua trung gian → DISABLE
                if (reachableViaIntermediate.indexOf(blogId) !== -1) {
                    $option.prop('disabled', true);
                    $option.addClass('tgs-ancestor-disabled');
                    $option.removeClass('tgs-descendant-disabled');
                    if (originalText.indexOf('[phải qua trung gian]') === -1) {
                        $option.text(originalText + ' [phải qua trung gian]');
                    }
                    return;
                }

                // Không bị disable → reset
                $option.prop('disabled', false);
                $option.removeClass('tgs-ancestor-disabled tgs-descendant-disabled');
                $option.text(originalText);
            });

            // Show/hide warning message
            var ancestorCount = $select.find('option.tgs-ancestor-disabled').length;
            var descendantCount = $select.find('option.tgs-descendant-disabled').length;

            if (ancestorCount > 0 || descendantCount > 0) {
                var messages = [];
                if (descendantCount > 0) {
                    messages.push(descendantCount + ' shop con/cháu (chọn sẽ tạo vòng lặp)');
                }
                if (ancestorCount > 0) {
                    messages.push(ancestorCount + ' shop tổ tiên (đã đến được qua trung gian)');
                }
                $warning.find('.warning-text').text('Đã ẩn: ' + messages.join(', ') + '.');
                $warning.show();
            } else {
                $warning.hide();
            }
        },

        /**
         * Tìm tất cả con/cháu (descendants) của một shop
         * Dùng BFS duyệt ngược từ hierarchy (parent → child)
         *
         * @param {Object} hierarchy - Map: blogId → [parentIds]
         * @param {number} blogId - Blog ID cần tìm descendants
         * @return {Array} Danh sách descendants
         */
        findAllDescendants: function(hierarchy, blogId) {
            var descendants = [];
            var visited = {};
            visited[blogId] = true;

            // Xây dựng children map từ hierarchy (đảo ngược parent → child)
            var children = {};
            for (var id in hierarchy) {
                if (hierarchy.hasOwnProperty(id)) {
                    var parentIds = hierarchy[id] || [];
                    parentIds.forEach(function(parentId) {
                        var pid = parseInt(parentId, 10);
                        if (!children[pid]) {
                            children[pid] = [];
                        }
                        children[pid].push(parseInt(id, 10));
                    });
                }
            }

            // BFS từ blogId tìm tất cả children
            var queue = [blogId];
            while (queue.length > 0) {
                var currentId = queue.shift();
                var childIds = children[currentId] || [];

                childIds.forEach(function(childId) {
                    if (!visited[childId]) {
                        visited[childId] = true;
                        descendants.push(childId);
                        queue.push(childId);
                    }
                });
            }

            return descendants;
        },

        /**
         * Tìm tất cả các shop có thể đến được từ các cha trực tiếp (qua đường đi gián tiếp)
         *
         * Logic: Duyệt BFS từ mỗi cha trực tiếp, tìm tất cả tổ tiên của chúng
         * Các tổ tiên này chính là những shop "có thể đến qua trung gian"
         *
         * @param {Object} hierarchy - Map: blogId → [parentIds]
         * @param {number} currentBlogId - Blog ID hiện tại
         * @param {Array} directParents - Danh sách cha trực tiếp đã cấu hình
         * @return {Array} Danh sách blogIds có thể đến qua trung gian
         */
        findReachableViaIntermediate: function(hierarchy, currentBlogId, directParents) {
            var reachable = [];
            var visited = {};

            // Đánh dấu currentBlog và directParents là đã xử lý (không disable)
            visited[currentBlogId] = true;
            directParents.forEach(function(p) {
                visited[p] = true;
            });

            // BFS từ mỗi cha trực tiếp để tìm tổ tiên
            var queue = directParents.slice(); // Copy array

            while (queue.length > 0) {
                var blogId = queue.shift();
                var parents = hierarchy[blogId] || [];

                parents.forEach(function(parentId) {
                    var parentIdInt = parseInt(parentId, 10);

                    if (!visited[parentIdInt]) {
                        visited[parentIdInt] = true;
                        // Đây là tổ tiên của cha trực tiếp → có thể đến qua trung gian
                        reachable.push(parentIdInt);
                        // Tiếp tục duyệt lên
                        queue.push(parentIdInt);
                    }
                });
            }

            return reachable;
        },

        /**
         * Get current date in Y-m-d format
         */
        getCurrentDate: function() {
            var now = new Date();
            var year = now.getFullYear();
            var month = String(now.getMonth() + 1).padStart(2, '0');
            var day = String(now.getDate()).padStart(2, '0');
            return year + '-' + month + '-' + day;
        },

        /**
         * Show notification
         */
        showNotification: function(message, type) {
            type = type || 'success';
            
            var $notification = $('<div class="notice notice-' + type + ' is-dismissible"><p>' + message + '</p></div>');
            
            $('.wrap h1').first().after($notification);
            
            // Auto dismiss after 5 seconds
            setTimeout(function() {
                $notification.fadeOut(function() {
                    $(this).remove();
                });
            }, 5000);
        },

        /**
         * Format number with thousands separator
         */
        formatNumber: function(num) {
            return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
        },

        /**
         * Format currency
         */
        formatCurrency: function(amount) {
            return this.formatNumber(Math.round(amount)) + ' đ';
        }
    };

    // Initialize on document ready
    $(document).ready(function() {
        TGSSyncAdmin.init();
    });

    // Expose to global scope if needed
    window.TGSSyncAdmin = TGSSyncAdmin;

})(jQuery);
