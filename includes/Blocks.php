<?php

declare(strict_types=1);

namespace PaymosWooCommerce;

use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

defined('ABSPATH') || exit;

final class Blocks extends AbstractPaymentMethodType
{
    /** @var string */
    protected $name = 'paymos';

    /** @var array<string, mixed> */
    private $settings = array();

    public function initialize()
    {
        $this->settings = Config::all();
    }

    public function is_active()
    {
        return isset($this->settings['enabled']) && $this->settings['enabled'] === 'yes';
    }

    public function get_payment_method_script_handles()
    {
        $asset = PAYMOS_WC_PLUGIN_DIR . 'assets/js/blocks/paymos-blocks.js';
        $handle = 'paymos-woocommerce-blocks';

        wp_register_script(
            $handle,
            plugins_url('assets/js/blocks/paymos-blocks.js', PAYMOS_WC_PLUGIN_FILE),
            array('wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n'),
            is_readable($asset) ? (string) filemtime($asset) : PAYMOS_WC_PLUGIN_VERSION,
            true
        );

        return array($handle);
    }

    /**
     * @return array<string, mixed>
     */
    public function get_payment_method_data()
    {
        return array(
            'title' => isset($this->settings['title']) ? (string) $this->settings['title'] : __('Pay with stablecoins', 'paymos-woocommerce'),
            'description' => isset($this->settings['description']) ? (string) $this->settings['description'] : __('Pay with USDT, USDC, DAI and other stablecoins on Tron, Ethereum, BSC, Polygon, Arbitrum, Optimism, Base or TON. No price volatility, no chargebacks, settlement on-chain in minutes.', 'paymos-woocommerce'),
            'supports' => array('products'),
        );
    }
}
