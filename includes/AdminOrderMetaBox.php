<?php

declare(strict_types=1);

namespace PaymosWooCommerce;

defined('ABSPATH') || exit;

final class AdminOrderMetaBox
{
    public static function register()
    {
        $screens = array('shop_order');

        if (function_exists('wc_get_page_screen_id')) {
            $screens[] = wc_get_page_screen_id('shop-order');
        }

        foreach (array_unique($screens) as $screen) {
            add_meta_box(
                'paymos-payment',
                __('Paymos Payment', 'paymos-woocommerce'),
                array(__CLASS__, 'render'),
                $screen,
                'side',
                'high'
            );
        }
    }

    public static function render($postOrOrderObject)
    {
        $order = self::order($postOrOrderObject);
        if (!$order) {
            echo '<p>' . esc_html__('Paymos order data is not available.', 'paymos-woocommerce') . '</p>';
            return;
        }

        $rows = array(
            __('Invoice ID', 'paymos-woocommerce') => self::meta($order, '_paymos_invoice_id'),
            __('External order ID', 'paymos-woocommerce') => self::meta($order, '_paymos_external_order_id'),
            __('Amount snapshot', 'paymos-woocommerce') => trim(self::meta($order, '_paymos_invoice_amount') . ' ' . self::meta($order, '_paymos_invoice_currency')),
            __('Last event', 'paymos-woocommerce') => self::meta($order, '_paymos_last_event_type'),
            __('Last event ID', 'paymos-woocommerce') => self::meta($order, '_paymos_last_event_id'),
            __('Last event at', 'paymos-woocommerce') => self::meta($order, '_paymos_last_event_at'),
            __('Last status', 'paymos-woocommerce') => self::meta($order, '_paymos_last_status'),
            __('Amount mismatch', 'paymos-woocommerce') => self::meta($order, '_paymos_amount_mismatch'),
        );

        echo '<table class="widefat striped">';
        foreach ($rows as $label => $value) {
            if ($value === '') {
                continue;
            }

            echo '<tr><th style="width:45%;">' . esc_html($label) . '</th><td><code>' . esc_html($value) . '</code></td></tr>';
        }
        echo '</table>';

        $paymentUrl = self::meta($order, '_paymos_payment_url');
        if ($paymentUrl !== '') {
            echo '<p><a class="button button-secondary" target="_blank" rel="noopener noreferrer" href="' . esc_url($paymentUrl) . '">' . esc_html__('Open Paymos checkout', 'paymos-woocommerce') . '</a></p>';
        }
    }

    private static function order($postOrOrderObject)
    {
        if (is_object($postOrOrderObject) && method_exists($postOrOrderObject, 'get_id') && method_exists($postOrOrderObject, 'get_meta')) {
            return $postOrOrderObject;
        }

        if (is_object($postOrOrderObject) && isset($postOrOrderObject->ID) && function_exists('wc_get_order')) {
            return wc_get_order((int) $postOrOrderObject->ID);
        }

        if (isset($_GET['id']) && function_exists('wc_get_order')) {
            $rawId = function_exists('wp_unslash') ? wp_unslash($_GET['id']) : $_GET['id'];
            return wc_get_order(absint($rawId));
        }

        return null;
    }

    private static function meta($order, $key)
    {
        if (!method_exists($order, 'get_meta')) {
            return '';
        }

        return (string) $order->get_meta($key, true);
    }
}
