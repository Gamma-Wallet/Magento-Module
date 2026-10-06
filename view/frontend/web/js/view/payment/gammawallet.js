define([
    'uiComponent',
    'Magento_Checkout/js/model/payment/renderer-list'
], function (Component, rendererList) {
    'use strict';

    rendererList.push({
        type: 'gammawallet',
        component: 'Gamma_Wallet/js/view/payment/method-renderer/gammawallet'
    });

    return Component.extend({});
});
