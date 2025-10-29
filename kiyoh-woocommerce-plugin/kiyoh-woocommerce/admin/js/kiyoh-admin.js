/**
 * Kiyoh WooCommerce Admin JavaScript
 */

(function ($) {
    'use strict';

    var KiyohAdmin = {

        init: function () {
            this.bindEvents();
            this.initTabs();
            this.validateForm();
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
                        KiyohAdmin.showProductSyncResult('success', response.data.message);
                    } else {
                        KiyohAdmin.showProductSyncResult('error', response.data.message || kiyoh_admin_ajax.strings.error);
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
                                message += ' (Average Rating: ' + stats.averageRating + ', Total Reviews: ' + (stats.numberOfReviews || 0) + ')';
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

        showProductSyncResult: function (type, message) {
            var noticeClass = 'notice-' + type;
            var $result = $('<div class="notice ' + noticeClass + '"><p>' + message + '</p></div>');

            $('#product-sync-results').html($result);

            // Auto-dismiss after 5 seconds
            setTimeout(function () {
                $result.fadeOut();
            }, 5000);
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