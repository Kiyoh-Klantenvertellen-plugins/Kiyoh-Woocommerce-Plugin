/**
 * Kiyoh WooCommerce Admin JavaScript
 */

(function ($) {
    'use strict';

    var KiyohAdmin = {

        ratingSyncTimer: null,
        ratingSyncRequest: null,
        ratingSyncActive: false,

        init: function () {
            this.bindEvents();
            this.initTabs();
            this.validateForm();
            this.initRatingSyncStatus();
        },

        bindEvents: function () {
            // Platform selection change handler
            $(document).on('change', '#kiyoh_platform', this.handlePlatformChange);

            // Form validation
            $(document).on('blur', 'input[name*="location_id"], input[name*="api_key"]', this.validateCredentials);

            // Bulk sync button
            $(document).on('click', '#bulk-sync-products', this.handleBulkSync);

            // Test API connection button
            $(document).on('click', '#test-api-connection', this.handleTestApiConnection);

            // Product rating sync button
            $(document).on('click', '#sync-product-ratings', this.handleRatingSync);

            // Auto-sync checkbox handler
            $(document).on('change', 'input[name*="auto_sync"]', this.handleAutoSyncChange);

            // Invitation type dropdown handler
            $(document).on('change', 'select[name*="invitation_type"]', this.handleInvitationTypeChange);

            // Multi-select helpers
            $(document).on('dblclick', 'select[multiple] option', this.handleMultiSelectDblClick);
        },

        initTabs: function () {
            // Set initial tab from PHP or URL
            var initialTab = window.kiyohAdminData ? window.kiyohAdminData.activeTab : 'general';
            var hash = window.location.hash;
            if (hash) {
                var tab = hash.replace('#', '');
                this.switchTab(tab);
            } else {
                this.switchTab(initialTab);
            }

            // Handle tab clicks
            $('.nav-tab').on('click', function (e) {
                e.preventDefault();
                var href = $(this).attr('href');
                var tab = href.split('tab=')[1];
                KiyohAdmin.switchTab(tab);

                // Update URL hash
                window.location.hash = tab;
            });

            // Handle browser back/forward
            $(window).on('hashchange', function () {
                var hash = window.location.hash;
                if (hash) {
                    var tab = hash.replace('#', '');
                    KiyohAdmin.switchTab(tab);
                }
            });
        },

        switchTab: function (tab) {
            // Update nav tabs
            $('.nav-tab').removeClass('nav-tab-active');
            $('.nav-tab[href*="tab=' + tab + '"]').addClass('nav-tab-active');

            // Show/hide tab panels
            $('.tab-panel').hide();
            $('.tab-' + tab).show();

            // Always show submit button since tools tab is removed
            $('#submit-section').show();
        },

        handlePlatformChange: function () {
            var platform = $(this).val();
            var $locationField = $('input[name*="location_id"]');
            var $apiKeyField = $('input[name*="api_key"]');

            // Update placeholder text based on platform
            if (platform === 'klantenvertellen') {
                $locationField.attr('placeholder', 'Your Klantenvertellen Location ID');
                $apiKeyField.attr('placeholder', 'Your Klantenvertellen API Key');
            } else {
                $locationField.attr('placeholder', 'Your Kiyoh Location ID');
                $apiKeyField.attr('placeholder', 'Your Kiyoh API Key');
            }

            // Clear validation status
            KiyohAdmin.clearValidationStatus();
        },

        validateCredentials: function () {
            var $locationId = $('input[name*="location_id"]');
            var $apiKey = $('input[name*="api_key"]');

            var hasLocationId = $locationId.val().trim().length > 0;
            var hasApiKey = $apiKey.val().trim().length > 0;

            // Enable/disable bulk sync button based on credentials
            var hasCredentials = hasLocationId && hasApiKey;
            $('#bulk-sync-products').prop('disabled', !hasCredentials);
            
            // Enable/disable test API connection button based on credentials
            $('#test-api-connection').prop('disabled', !hasCredentials);

            $('#sync-product-ratings').prop('disabled', !hasCredentials || KiyohAdmin.ratingSyncActive);

            return hasCredentials;
        },

        validateForm: function () {
            // Initial validation
            this.validateCredentials();

            // Initialize invitation type field visibility
            var $invitationTypeField = $('select[name*="invitation_type"]');
            if ($invitationTypeField.length) {
                this.handleInvitationTypeChange.call($invitationTypeField[0]);
            }

            // Initialize auto-sync field visibility
            var $autoSyncField = $('input[name*="auto_sync"]');
            if ($autoSyncField.length) {
                this.handleAutoSyncChange.call($autoSyncField[0]);
            }

            // Enable/disable bulk sync based on credentials
            if (window.kiyohAdminData && window.kiyohAdminData.hasCredentials) {
                $('#bulk-sync-products').prop('disabled', false);
                $('#test-api-connection').prop('disabled', false);
                $('#sync-product-ratings').prop('disabled', KiyohAdmin.ratingSyncActive);
            }
        },

        clearValidationStatus: function () {
            $('.kiyoh-validation-status').remove();
        },

        handleBulkSync: function (e) {
            e.preventDefault();

            if (!KiyohAdmin.validateCredentials()) {
                KiyohAdmin.showNotice('error', 'Please configure your API credentials first.');
                return;
            }

            var $button = $(this);
            var originalText = $button.text();

            $button.prop('disabled', true).html('<span class="kiyoh-spinner"></span> ' + kiyoh_admin_ajax.strings.syncing);

            $.ajax({
                url: kiyoh_admin_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'kiyoh_bulk_sync',
                    nonce: kiyoh_admin_ajax.nonce
                },
                success: function (response) {
                    if (response.success) {
                        var payload = response.data || {};
                        var hasErrors = payload.has_errors && payload.error_messages && payload.error_messages.length;
                        // If any batch failed, show a warning/error notice WITH the
                        // detailed API messages so the cause is visible in the panel.
                        KiyohAdmin.showProductSyncResult(
                            hasErrors ? 'error' : 'success',
                            payload.message,
                            hasErrors ? payload.error_messages : null
                        );
                    } else {
                        var errData = response.data || {};
                        KiyohAdmin.showProductSyncResult(
                            'error',
                            errData.message || kiyoh_admin_ajax.strings.error,
                            errData.error_messages || null
                        );
                    }
                },
                error: function () {
                    KiyohAdmin.showProductSyncResult('error', kiyoh_admin_ajax.strings.error);
                },
                complete: function () {
                    $button.prop('disabled', false).text(originalText);
                }
            });
        },

        initRatingSyncStatus: function () {
            if (!$('#sync-product-ratings').length) {
                return;
            }

            var sync = kiyoh_admin_ajax.rating_sync || {};
            KiyohAdmin.renderRatingSyncStatus(sync);
            if (sync.active) {
                KiyohAdmin.startRatingSyncPolling();
            }
        },

        handleRatingSync: function (e) {
            e.preventDefault();

            if (!KiyohAdmin.validateCredentials()) {
                KiyohAdmin.showNotice('error', 'Please configure your API credentials first.');
                return;
            }

            KiyohAdmin.setRatingSyncButtonState(true, kiyoh_admin_ajax.strings.sync_queued);
            KiyohAdmin.showRatingSyncProgress(kiyoh_admin_ajax.strings.sync_queued, 0);

            $.ajax({
                url: kiyoh_admin_ajax.ajax_url,
                type: 'POST',
                timeout: 120000,
                data: {
                    action: 'kiyoh_sync_product_ratings',
                    nonce: kiyoh_admin_ajax.nonce
                },
                success: function (response) {
                    var sync = (response.data && response.data.sync) ? response.data.sync : {};
                    if (response.success) {
                        KiyohAdmin.renderRatingSyncStatus(sync);
                        if (sync.active) {
                            KiyohAdmin.startRatingSyncPolling();
                        }
                    } else {
                        var errData = response.data || {};
                        KiyohAdmin.renderRatingSyncStatus(errData.sync || { status: 'failed', error: errData.message });
                        KiyohAdmin.showRatingSyncResult('error', errData.message || kiyoh_admin_ajax.strings.error);
                    }
                },
                error: function () {
                    KiyohAdmin.setRatingSyncButtonState(false);
                    KiyohAdmin.showRatingSyncResult('error', kiyoh_admin_ajax.strings.error);
                }
            });
        },

        startRatingSyncPolling: function () {
            if (KiyohAdmin.ratingSyncTimer || KiyohAdmin.ratingSyncRequest) {
                return;
            }

            KiyohAdmin.pollRatingSyncStatus();
        },

        stopRatingSyncPolling: function () {
            if (KiyohAdmin.ratingSyncTimer) {
                clearTimeout(KiyohAdmin.ratingSyncTimer);
                KiyohAdmin.ratingSyncTimer = null;
            }
        },

        pollRatingSyncStatus: function () {
            if (KiyohAdmin.ratingSyncRequest) {
                return;
            }

            KiyohAdmin.ratingSyncRequest = $.ajax({
                url: kiyoh_admin_ajax.ajax_url,
                type: 'POST',
                timeout: 120000,
                data: {
                    action: 'kiyoh_rating_sync_status',
                    nonce: kiyoh_admin_ajax.nonce
                },
                success: function (response) {
                    var sync = (response.data && response.data.sync) ? response.data.sync : {};
                    KiyohAdmin.renderRatingSyncStatus(sync);
                    if (!sync.active) {
                        KiyohAdmin.stopRatingSyncPolling();
                        return;
                    }

                    KiyohAdmin.ratingSyncTimer = setTimeout(function () {
                        KiyohAdmin.ratingSyncTimer = null;
                        KiyohAdmin.pollRatingSyncStatus();
                    }, 1000);
                },
                error: function () {
                    KiyohAdmin.ratingSyncTimer = setTimeout(function () {
                        KiyohAdmin.ratingSyncTimer = null;
                        KiyohAdmin.pollRatingSyncStatus();
                    }, 3000);
                },
                complete: function () {
                    KiyohAdmin.ratingSyncRequest = null;
                }
            });
        },

        renderRatingSyncStatus: function (sync) {
            sync = sync || {};

            KiyohAdmin.ratingSyncActive = !!sync.active;

            if (sync.last_sync) {
                var lastSyncText = kiyoh_admin_ajax.strings.last_sync.replace('%s', sync.last_sync);
                var $last = $('#rating-sync-last');
                if ($last.length) {
                    $last.text(lastSyncText).show();
                }
            }

            if (sync.active) {
                var progress = sync.message || kiyoh_admin_ajax.strings.sync_queued;
                if (!sync.message && typeof sync.processed !== 'undefined') {
                    progress = kiyoh_admin_ajax.strings.syncing_progress
                        .replace('%1$d', sync.processed)
                        .replace('%2$d', sync.total || 0);
                }
                KiyohAdmin.showRatingSyncProgress(progress, sync.percent || 0);
                KiyohAdmin.setRatingSyncButtonState(true, progress);
                return;
            }

            KiyohAdmin.hideRatingSyncProgress();
            KiyohAdmin.setRatingSyncButtonState(false);

            if (sync.status === 'completed' && sync.message) {
                KiyohAdmin.showRatingSyncResult('success', sync.message);
            } else if (sync.status === 'failed' && (sync.error || sync.message)) {
                KiyohAdmin.showRatingSyncResult('error', sync.error || sync.message);
            }
        },

        setRatingSyncButtonState: function (running, label) {
            var $button = $('#sync-product-ratings');
            if (!$button.length) {
                return;
            }

            if (running) {
                $button.prop('disabled', true).html('<span class="kiyoh-spinner"></span> ' + KiyohAdmin.escapeHtml(label || kiyoh_admin_ajax.strings.syncing));
                return;
            }

            $button.prop('disabled', false).text($button.data('idle-label') || 'Sync Ratings Now');
        },

        showRatingSyncProgress: function (text, percent) {
            var $wrap = $('#rating-sync-progress');
            if (!$wrap.length) {
                return;
            }

            $wrap.show();
            $wrap.find('.kiyoh-rating-sync-progress-text').text(text);
            $wrap.find('.kiyoh-rating-sync-progress-bar span').css('width', Math.max(0, Math.min(100, percent || 0)) + '%');
        },

        hideRatingSyncProgress: function () {
            $('#rating-sync-progress').hide();
        },

        handleTestApiConnection: function (e) {
            e.preventDefault();

            if (!KiyohAdmin.validateCredentials()) {
                KiyohAdmin.showApiTestResult('error', 'Please configure your API credentials first.');
                return;
            }

            var $button = $(this);
            var originalText = $button.text();

            $button.prop('disabled', true).html('<span class="kiyoh-spinner"></span> Testing...');

            $.ajax({
                url: kiyoh_admin_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'kiyoh_test_api_connection',
                    nonce: kiyoh_admin_ajax.nonce
                },
                success: function (response) {
                    if (response.success) {
                        var message = response.data.message;
                        if (response.data.stats) {
                            var stats = response.data.stats;
                            if (stats.averageRating) {
                                message += ' (Average Rating: ' + stats.averageRating + ', Total Reviews: ' + (stats.numberReviews || 0) + ')';
                            }
                        }
                        KiyohAdmin.showApiTestResult('success', message);
                    } else {
                        KiyohAdmin.showApiTestResult('error', response.data.message || kiyoh_admin_ajax.strings.error);
                    }
                },
                error: function () {
                    KiyohAdmin.showApiTestResult('error', kiyoh_admin_ajax.strings.error);
                },
                complete: function () {
                    $button.prop('disabled', false).text(originalText);
                }
            });
        },





        handleAutoSyncChange: function () {
            var isEnabled = $(this).is(':checked');
            var $excludeFields = $('select[name*="excluded_types"], textarea[name*="excluded_codes"]');

            if (isEnabled) {
                $excludeFields.closest('tr').show();
            } else {
                $excludeFields.closest('tr').hide();
            }
        },

        handleInvitationTypeChange: function () {
            var invitationType = $(this).val();
            var $maxProductsField = $('input[name*="max_products"]').closest('tr');
            var $productSortField = $('select[name*="product_sort_order"]').closest('tr');

            // Show product-related fields only when products are included in invitations
            if (invitationType === 'shop_only') {
                $maxProductsField.hide();
                $productSortField.hide();
            } else {
                $maxProductsField.show();
                $productSortField.show();
            }
        },

        handleMultiSelectDblClick: function () {
            $(this).prop('selected', !$(this).prop('selected'));
        },

        showNotice: function (type, message) {
            var noticeClass = 'notice-' + type;
            var $notice = $('<div class="notice ' + noticeClass + ' is-dismissible"><p>' + message + '</p></div>');

            $('.wrap h1').after($notice);

            // Auto-dismiss after 5 seconds
            setTimeout(function () {
                $notice.fadeOut();
            }, 5000);
        },

        // Escape user/API-provided text before injecting into the DOM.
        escapeHtml: function (str) {
            return $('<div>').text(str == null ? '' : String(str)).html();
        },

        showProductSyncResult: function (type, message, errorMessages) {
            var noticeClass = 'notice-' + type;
            var html = '<p>' + KiyohAdmin.escapeHtml(message) + '</p>';

            // When the API returned detailed error(s), list them verbatim so the
            // merchant can see exactly why the sync failed (validation, auth, etc.).
            if (errorMessages && errorMessages.length) {
                html += '<p><strong>' + KiyohAdmin.escapeHtml('Details:') + '</strong></p><ul style="margin-left:18px;list-style:disc;">';
                for (var i = 0; i < errorMessages.length; i++) {
                    // Raw API error dumps can be long JSON strings; render them in a
                    // monospaced, wrapping block so nothing is truncated or hidden.
                    html += '<li><code style="white-space:pre-wrap;word-break:break-all;display:block;">' + KiyohAdmin.escapeHtml(errorMessages[i]) + '</code></li>';
                }
                html += '</ul>';
            }

            var $result = $('<div class="notice ' + noticeClass + '">' + html + '</div>');
            $('#product-sync-results').html($result);

            // Keep error notices on screen so they can be read/copied; only
            // auto-dismiss success notices.
            if (type === 'success' && !(errorMessages && errorMessages.length)) {
                setTimeout(function () {
                    $result.fadeOut();
                }, 5000);
            }
        },

        showRatingSyncResult: function (type, message) {
            var noticeClass = 'notice-' + type;
            var $result = $('<div class="notice ' + noticeClass + '"><p>' + KiyohAdmin.escapeHtml(message) + '</p></div>');

            $('#rating-sync-results').html($result);

            if (type === 'success') {
                setTimeout(function () {
                    $result.fadeOut();
                }, 8000);
            }
        },

        showApiTestResult: function (type, message) {
            var noticeClass = 'notice-' + type;
            var $result = $('<div class="notice ' + noticeClass + '"><p>' + message + '</p></div>');

            $('#api-test-results').html($result);

            // Auto-dismiss after 10 seconds for test results
            setTimeout(function () {
                $result.fadeOut();
            }, 10000);
        },

        // Utility functions
        debounce: function (func, wait) {
            var timeout;
            return function executedFunction() {
                var context = this;
                var args = arguments;
                var later = function () {
                    timeout = null;
                    func.apply(context, args);
                };
                clearTimeout(timeout);
                timeout = setTimeout(later, wait);
            };
        }
    };

    // Initialize when document is ready
    $(document).ready(function () {
        KiyohAdmin.init();
    });

    // Make KiyohAdmin globally available for debugging
    window.KiyohAdmin = KiyohAdmin;

})(jQuery);