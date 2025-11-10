/**
 * YoCo Backorder System Admin Scripts
 */

(function($) {
    'use strict';
    
    var YoCoAdmin = {
        
        init: function() {
            this.bindEvents();
            this.initComponents();
        },
        
        bindEvents: function() {
            // Test supplier feed
            $(document).on('click', '#test-feed', this.testSupplierFeed);
            
            // Manual sync
            $(document).on('click', '#sync-supplier', this.syncSupplier);
            
            // Check product stock
            $(document).on('click', '.yoco-check-stock', this.checkProductStock);
            
            // Add/remove time inputs
            $(document).on('click', '#add-time', this.addTimeInput);
            $(document).on('click', '.remove-time', this.removeTimeInput);
            
            // Update frequency change
            $(document).on('change', '#update_frequency', this.updateTimeInputs);
            
            // CSV delimiter change
            $(document).on('change', '#csv_delimiter', this.onDelimiterChange);
        },
        
        initComponents: function() {
            // Initialize any components that need setup on page load
            this.updateCurrentTime();
            setInterval(this.updateCurrentTime, 60000); // Update every minute
        },
        
        testSupplierFeed: function(e) {
            e.preventDefault();
            
            var button = $(this);
            var feedUrl = $('#feed_url').val();
            var delimiter = $('#csv_delimiter').val();
            
            if (!feedUrl) {
                YoCoAdmin.showNotice('error', yoco_admin.i18n.error + ': Please enter a feed URL first');
                return;
            }
            
            YoCoAdmin.setLoading(button, true, yoco_admin.i18n.testing_feed);
            
            $.ajax({
                url: yoco_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'yoco_test_supplier_feed',
                    feed_url: feedUrl,
                    delimiter: delimiter,
                    nonce: yoco_admin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        YoCoAdmin.showFeedTestResult('success', response.message, response.data);
                        YoCoAdmin.updateColumnMappings(response.data.columns);
                    } else {
                        YoCoAdmin.showFeedTestResult('error', response.message);
                    }
                },
                error: function(xhr, status, error) {
                    YoCoAdmin.showFeedTestResult('error', 'AJAX Error: ' + error);
                },
                complete: function() {
                    YoCoAdmin.setLoading(button, false, 'Test Feed');
                }
            });
        },
        
        syncSupplier: function(e) {
            e.preventDefault();
            
            var button = $(this);
            var supplierId = $('input[name="supplier_id"]').val();
            
            if (!confirm('Are you sure you want to start manual sync?')) {
                return;
            }
            
            YoCoAdmin.setLoading(button, true, yoco_admin.i18n.syncing);
            
            $.ajax({
                url: yoco_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'yoco_sync_supplier',
                    supplier_id: supplierId,
                    nonce: yoco_admin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        YoCoAdmin.showNotice('success', response.message);
                        // Optionally refresh page or update UI
                        setTimeout(function() {
                            location.reload();
                        }, 2000);
                    } else {
                        YoCoAdmin.showNotice('error', 'Sync failed: ' + response.message);
                    }
                },
                error: function(xhr, status, error) {
                    YoCoAdmin.showNotice('error', 'AJAX Error during sync: ' + error);
                },
                complete: function() {
                    YoCoAdmin.setLoading(button, false, 'Manual Sync');
                }
            });
        },
        
        checkProductStock: function(e) {
            e.preventDefault();
            
            var button = $(this);
            var productId = button.data('product-id');
            
            YoCoAdmin.setLoading(button, true, yoco_admin.i18n.checking_stock);
            
            $.ajax({
                url: yoco_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'yoco_check_product_stock',
                    product_id: productId,
                    nonce: yoco_admin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        YoCoAdmin.showNotice('success', response.message);
                        // Refresh the supplier stock info
                        setTimeout(function() {
                            location.reload();
                        }, 1000);
                    } else {
                        YoCoAdmin.showNotice('error', 'Stock check failed: ' + response.message);
                    }
                },
                error: function(xhr, status, error) {
                    YoCoAdmin.showNotice('error', 'AJAX Error during stock check: ' + error);
                },
                complete: function() {
                    YoCoAdmin.setLoading(button, false, 'Refresh Supplier Stock');
                }
            });
        },
        
        addTimeInput: function(e) {
            e.preventDefault();
            
            var timeHtml = '<div class="time-input">' +
                '<input type="time" name="update_times[]" value="09:00">' +
                '<button type="button" class="button button-small remove-time" style="margin-left: 5px;">Remove</button>' +
                '</div>';
            
            $('#update-times').append(timeHtml);
            YoCoAdmin.updateTimeInputsVisibility();
        },
        
        removeTimeInput: function(e) {
            e.preventDefault();
            $(this).closest('.time-input').remove();
            YoCoAdmin.updateTimeInputsVisibility();
        },
        
        updateTimeInputs: function() {
            var frequency = parseInt($('#update_frequency').val()) || 1;
            var currentInputs = $('.time-input').length;
            
            if (frequency > currentInputs) {
                // Add more time inputs
                for (var i = currentInputs; i < frequency; i++) {
                    $('#add-time').trigger('click');
                }
            } else if (frequency < currentInputs) {
                // Remove excess time inputs
                $('.time-input').slice(frequency).remove();
            }
        },
        
        updateTimeInputsVisibility: function() {
            var inputs = $('.time-input');
            inputs.find('.remove-time').toggle(inputs.length > 1);
        },
        
        onDelimiterChange: function() {
            // Clear previous test results when delimiter changes
            $('#feed-test-result').empty();
            $('#csv-mapping input').val('');
        },
        
        showFeedTestResult: function(type, message, data) {
            var html = '<div class="yoco-notice ' + type + '">';
            html += '<strong>' + (type === 'success' ? yoco_admin.i18n.success : yoco_admin.i18n.error) + '</strong><br>';
            html += message;
            
            if (data && type === 'success') {
                html += '<br><br><strong>Details:</strong>';
                html += '<br>Total rows: ' + data.total_rows;
                html += '<br>Available columns: ' + data.columns.join(', ');
                if (data.delimiter_detected && data.delimiter_detected !== $('#csv_delimiter').val()) {
                    html += '<br><em>Detected delimiter: ' + data.delimiter_detected + '</em>';
                }
            }
            
            html += '</div>';
            $('#feed-test-result').html(html);
        },
        
        updateColumnMappings: function(columns) {
            // Create datalists for column suggestions
            var skuDatalist = $('#sku-datalist');
            var stockDatalist = $('#stock-datalist');
            
            if (skuDatalist.length === 0) {
                $('body').append('<datalist id="sku-datalist"></datalist>');
                $('#sku_column').attr('list', 'sku-datalist');
                skuDatalist = $('#sku-datalist');
            }
            
            if (stockDatalist.length === 0) {
                $('body').append('<datalist id="stock-datalist"></datalist>');
                $('#stock_column').attr('list', 'stock-datalist');
                stockDatalist = $('#stock-datalist');
            }
            
            // Clear and populate datalists
            skuDatalist.empty();
            stockDatalist.empty();
            
            columns.forEach(function(column) {
                skuDatalist.append('<option value="' + column + '">');
                stockDatalist.append('<option value="' + column + '">');
            });
            
            // Auto-suggest common column names
            YoCoAdmin.autoSuggestColumns(columns);
        },
        
        autoSuggestColumns: function(columns) {
            var skuSuggestions = ['sku', 'artikelnummer', 'article_number', 'product_code', 'code'];
            var stockSuggestions = ['stock', 'voorraad', 'quantity', 'qty', 'aantal', 'stock_quantity'];
            
            // Suggest SKU column
            if (!$('#sku_column').val()) {
                for (var i = 0; i < skuSuggestions.length; i++) {
                    var suggestion = skuSuggestions[i];
                    var match = columns.find(function(col) {
                        return col.toLowerCase().indexOf(suggestion) !== -1;
                    });
                    if (match) {
                        $('#sku_column').val(match);
                        break;
                    }
                }
            }
            
            // Suggest stock column
            if (!$('#stock_column').val()) {
                for (var i = 0; i < stockSuggestions.length; i++) {
                    var suggestion = stockSuggestions[i];
                    var match = columns.find(function(col) {
                        return col.toLowerCase().indexOf(suggestion) !== -1;
                    });
                    if (match) {
                        $('#stock_column').val(match);
                        break;
                    }
                }
            }
        },
        
        setLoading: function(element, loading, text) {
            if (loading) {
                element.prop('disabled', true).addClass('yoco-loading');
                if (text) {
                    element.data('original-text', element.text()).text(text);
                }
            } else {
                element.prop('disabled', false).removeClass('yoco-loading');
                if (element.data('original-text')) {
                    element.text(element.data('original-text')).removeData('original-text');
                } else if (text) {
                    element.text(text);
                }
            }
        },
        
        showNotice: function(type, message) {
            var noticeHtml = '<div class="yoco-notice ' + type + '">' + message + '</div>';
            
            // Remove existing notices
            $('.yoco-notice').remove();
            
            // Add new notice
            $('.wrap h1').after(noticeHtml);
            
            // Auto-hide success notices
            if (type === 'success') {
                setTimeout(function() {
                    $('.yoco-notice.success').fadeOut();
                }, 5000);
            }
        },
        
        updateCurrentTime: function() {
            var now = new Date();
            var timeString = now.toLocaleTimeString('nl-NL', { 
                hour: '2-digit', 
                minute: '2-digit',
                hour12: false 
            });
            
            $('.current-time').text(timeString);
        }
    };
    
    // Initialize when document is ready
    $(document).ready(function() {
        YoCoAdmin.init();
    });
    
    // Expose to global scope if needed
    window.YoCoAdmin = YoCoAdmin;
    
})(jQuery);