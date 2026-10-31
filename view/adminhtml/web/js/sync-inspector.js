/**
 * MageCopilot - AI-Powered Store Operations Platform
 * Single Pane of Glass Sync Inspector AMD Module
 */
define(['jquery'], function($) {
    'use strict';

    return function(config, element) {
        var productId = config.productId;
        var syncUrl = config.syncUrl;
        var previewUrl = config.previewUrl;
        var $el = $(element);

        // Modular initialization support
        $el.data('product-id', productId);
        $el.data('sync-url', syncUrl);
        $el.data('preview-url', previewUrl);
    };
});
