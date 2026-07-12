<?php

declare(strict_types=1);

namespace PaymosWooCommerce;

defined('ABSPATH') || exit;

/**
 * Buyer- and merchant-facing presentation hooks that surface Paymos data
 * WooCommerce already stores on the order: a resume-payment link in the
 * customer account, a Settings shortcut on the Plugins screen, and the
 * on-chain transaction hash + explorer link on the thank-you page and order
 * emails. None of these touch the payment flow — they only render existing
 * order meta.
 */
final class StorefrontHooks
{
    public static function register()
    {
        add_filter('woocommerce_my_account_my_orders_actions', array(__CLASS__, 'my_orders_actions'), 10, 2);
        add_filter('plugin_action_links_' . plugin_basename(PAYMOS_WC_PLUGIN_FILE), array(__CLASS__, 'plugin_action_links'));
        add_action('woocommerce_thankyou', array(__CLASS__, 'thankyou'), 10, 1);
        add_action('woocommerce_email_after_order_table', array(__CLASS__, 'email_transaction'), 10, 4);
    }

    /**
     * Add a "Pay invoice" action to an unpaid Paymos order in My Account so the
     * buyer can return to the hosted invoice they already started.
     *
     * @param array<string, array<string, string>> $actions
     * @param \WC_Order $order
     * @return array<string, array<string, string>>
     */
    public static function my_orders_actions($actions, $order)
    {
        if (!is_object($order) || $order->get_payment_method() !== 'paymos' || !$order->needs_payment()) {
            return $actions;
        }

        $url = (string) $order->get_meta('_paymos_payment_url');
        if ($url === '') {
            return $actions;
        }

        // Replace WC's default "pay" action (which re-runs checkout and mints a
        // new invoice) with a direct link back to the existing hosted invoice.
        unset($actions['pay']);
        $actions['paymos_pay'] = array(
            'url' => esc_url($url),
            'name' => __('Pay invoice', 'paymos-for-woocommerce'),
            'aria-label' => __('Pay the Paymos invoice for this order', 'paymos-for-woocommerce'),
        );

        return $actions;
    }

    /**
     * @param array<int|string, string> $actions
     * @return array<int|string, string>
     */
    public static function plugin_action_links($actions)
    {
        $url = admin_url('admin.php?page=wc-settings&tab=checkout&section=paymos');
        $link = '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'paymos-for-woocommerce') . '</a>';
        array_unshift($actions, $link);

        return $actions;
    }

    /**
     * @param int $order_id
     */
    public static function thankyou($order_id)
    {
        $order = wc_get_order($order_id);
        if (!$order || $order->get_payment_method() !== 'paymos') {
            return;
        }

        $hash = (string) $order->get_meta('_paymos_tx_hash');
        if ($hash === '') {
            return;
        }

        $explorer = (string) $order->get_meta('_paymos_explorer_url');

        echo '<section class="woocommerce-order-paymos-transaction">';
        echo '<h2>' . esc_html__('Payment confirmation', 'paymos-for-woocommerce') . '</h2>';
        echo '<p>' . esc_html__('Your payment was settled on-chain.', 'paymos-for-woocommerce') . ' ';
        if ($explorer !== '') {
            echo '<a href="' . esc_url($explorer) . '" target="_blank" rel="noopener noreferrer">'
                . esc_html__('View transaction', 'paymos-for-woocommerce') . '</a>.';
        } else {
            echo esc_html__('Transaction:', 'paymos-for-woocommerce') . ' <code>' . esc_html($hash) . '</code>';
        }
        echo '</p>';
        echo '</section>';
    }

    /**
     * @param \WC_Order $order
     * @param bool $sent_to_admin
     * @param bool $plain_text
     * @param \WC_Email $email
     */
    public static function email_transaction($order, $sent_to_admin, $plain_text, $email)
    {
        if (!is_object($order) || $order->get_payment_method() !== 'paymos') {
            return;
        }

        $hash = (string) $order->get_meta('_paymos_tx_hash');
        if ($hash === '') {
            return;
        }

        $explorer = (string) $order->get_meta('_paymos_explorer_url');

        if ($plain_text) {
            echo "\n" . esc_html__('Payment transaction:', 'paymos-for-woocommerce') . ' ' . esc_html($hash) . "\n";
            if ($explorer !== '') {
                echo esc_html__('View on explorer:', 'paymos-for-woocommerce') . ' ' . esc_url($explorer) . "\n";
            }
            return;
        }

        echo '<h2>' . esc_html__('Payment confirmation', 'paymos-for-woocommerce') . '</h2>';
        echo '<p>';
        if ($explorer !== '') {
            echo esc_html__('Settled on-chain.', 'paymos-for-woocommerce') . ' '
                . '<a href="' . esc_url($explorer) . '" target="_blank" rel="noopener noreferrer">'
                . esc_html__('View transaction', 'paymos-for-woocommerce') . '</a>.';
        } else {
            echo esc_html__('Transaction:', 'paymos-for-woocommerce') . ' <code>' . esc_html($hash) . '</code>';
        }
        echo '</p>';
    }
}
