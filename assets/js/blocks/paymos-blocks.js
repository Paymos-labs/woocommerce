(function () {
    var settings = window.wc.wcSettings.getSetting('paymos_data', {});
    var decode = window.wp.htmlEntities.decodeEntities;
    var createElement = window.wp.element.createElement;
    var registerPaymentMethod = window.wc.wcBlocksRegistry.registerPaymentMethod;
    var __ = window.wp.i18n.__;

    var label = decode(settings.title || __('Paymos', 'paymos-for-woocommerce'));
    var description = decode(settings.description || __('Pay with USDT or USDC across 13 networks — Tron, Ethereum, Polygon, Base, Solana and more. No price volatility, no chargebacks, settlement on-chain in minutes.', 'paymos-for-woocommerce'));

    var Content = function () {
        return createElement('div', null, description);
    };

    registerPaymentMethod({
        name: 'paymos',
        label: label,
        content: createElement(Content, null),
        edit: createElement(Content, null),
        canMakePayment: function () {
            return true;
        },
        ariaLabel: label,
        supports: {
            features: settings.supports || ['products']
        }
    });
}());
