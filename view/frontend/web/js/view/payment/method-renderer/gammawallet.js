/**
 * "Use Store Credits with Gamma": nothing to enter. After the order is placed, the success page shows
 * the QR code the customer scans with Gamma Wallet.
 */
define([
    'Magento_Checkout/js/view/payment/default'
], function (Component) {
    'use strict';

    return Component.extend({
        defaults: {
            template: 'Gamma_Wallet/payment/gammawallet'
        },

        getInstructions: function () {
            var config = window.checkoutConfig.payment.gammawallet || {};

            return config.instructions || '';
        },

        getLogoUrl: function () {
            var config = window.checkoutConfig.payment.gammawallet || {};

            return config.logo || '';
        }
    });
});
