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
    protected $settings = array();

    public function initialize()
    {
        $this->settings = Config::all();
    }

    public function is_active()
    {
        return isset($this->settings['enabled'])
            && $this->settings['enabled'] === 'yes'
            && Config::has_environment(Config::mode());
    }

    public function get_payment_method_script_handles()
    {
        $asset = PAYMOS_WC_PLUGIN_DIR . 'assets/js/blocks/paymos-blocks.js';
        $handle = 'paymos-for-woocommerce-blocks';

        wp_register_script(
            $handle,
            plugins_url('assets/js/blocks/paymos-blocks.js', PAYMOS_WC_PLUGIN_FILE),
            array('wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-html-entities', 'wp-i18n'),
            is_readable($asset) ? (string) filemtime($asset) : PAYMOS_WC_PLUGIN_VERSION,
            true
        );
        wp_set_script_translations($handle, 'paymos-for-woocommerce');

        return array($handle);
    }

    /**
     * @return array<string, mixed>
     */
    public function get_payment_method_data()
    {
        return array(
            'title' => isset($this->settings['title']) ? (string) $this->settings['title'] : __('Pay with stablecoins', 'paymos-for-woocommerce'),
            'description' => isset($this->settings['description']) ? (string) $this->settings['description'] : __('Pay with USDT or USDC across 13 networks — Tron, Ethereum, Polygon, Base, Solana and more. No price volatility, no chargebacks, settlement on-chain in minutes.', 'paymos-for-woocommerce'),
            'supports' => array('products'),
        );
    }
}
