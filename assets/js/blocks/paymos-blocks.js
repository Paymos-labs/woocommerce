(function () {
    var settings = window.wc.wcSettings.getSetting('paymos_data', {});
    var decode = window.wp.htmlEntities.decodeEntities;
    var createElement = window.wp.element.createElement;
    var registerPaymentMethod = window.wc.wcBlocksRegistry.registerPaymentMethod;

    var label = decode(settings.title || 'Paymos');
    var description = decode(settings.description || 'Pay with USDT, USDC, DAI and other stablecoins on Tron, Ethereum, BSC, Polygon, Arbitrum, Optimism, Base or TON. No price volatility, no chargebacks, settlement on-chain in minutes.');

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
