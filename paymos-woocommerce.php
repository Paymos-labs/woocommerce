<?php
/**
 * Plugin Name: Paymos for WooCommerce
 * Plugin URI: https://paymos.io
 * Description: Accept stablecoin payments in WooCommerce through Paymos.
 * Version: 1.0.5
 * Author: Paymos
 * Author URI: https://paymos.io
 * License: GPL-2.0-or-later
 * Text Domain: paymos-for-woocommerce
 * Requires at least: 6.2
 * Tested up to: 7.0
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 8.0
 * WC tested up to: 10.9
 */

defined('ABSPATH') || exit;

define('PAYMOS_WC_PLUGIN_FILE', __FILE__);
define('PAYMOS_WC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('PAYMOS_WC_PLUGIN_VERSION', '1.0.5');

require_once PAYMOS_WC_PLUGIN_DIR . 'includes/Autoloader.php';

PaymosWooCommerce\Autoloader::register();

PaymosWooCommerce\ConnectController::register();

add_action('before_woocommerce_init', static function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'custom_order_tables',
            PAYMOS_WC_PLUGIN_FILE,
            true
        );
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility(
            'cart_checkout_blocks',
            PAYMOS_WC_PLUGIN_FILE,
            true
        );
    }
});

add_action('plugins_loaded', static function () {
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    add_filter('woocommerce_payment_gateways', static function ($methods) {
        $methods[] = PaymosWooCommerce\Gateway::class;
        return $methods;
    });
});

add_action('woocommerce_blocks_loaded', static function () {
    if (!class_exists('\Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
        return;
    }

    add_action('woocommerce_blocks_payment_method_type_registration', static function ($registry) {
        $registry->register(new PaymosWooCommerce\Blocks());
    });
});

add_action('add_meta_boxes', static function () {
    PaymosWooCommerce\AdminOrderMetaBox::register();
});

PaymosWooCommerce\StorefrontHooks::register();

add_action('rest_api_init', static function () {
    PaymosWooCommerce\WebhookController::register_routes();
});

add_filter('cron_schedules', array(PaymosWooCommerce\Reconciler::class, 'cron_schedules'));
add_action('init', array(PaymosWooCommerce\Reconciler::class, 'maybe_schedule'));
add_action(PaymosWooCommerce\Reconciler::HOOK, array(PaymosWooCommerce\Reconciler::class, 'run'));

register_deactivation_hook(PAYMOS_WC_PLUGIN_FILE, array(PaymosWooCommerce\Reconciler::class, 'unschedule'));
register_activation_hook(PAYMOS_WC_PLUGIN_FILE, static function () {
    PaymosWooCommerce\EventStore::install();
});
