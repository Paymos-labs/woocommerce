<?php

declare(strict_types=1);

namespace PaymosWooCommerce;

use Paymos\Plugin\AmountGuard;

defined('ABSPATH') || exit;

final class OrderAmountGuard
{
    public static function capture($order, $amount, $currency)
    {
        $order->update_meta_data('_paymos_invoice_amount', self::formatAmount($amount));
        $order->update_meta_data('_paymos_invoice_currency', strtoupper((string) $currency));
        $order->update_meta_data('_paymos_amount_mismatch', 'no');
    }

    public static function currentMatchesSnapshot($order)
    {
        $savedAmount = self::meta($order, '_paymos_invoice_amount');
        $savedCurrency = strtoupper(self::meta($order, '_paymos_invoice_currency'));

        if ($savedAmount === '' || $savedCurrency === '') {
            return true;
        }

        return $savedAmount === self::formatAmount($order->get_total())
            && $savedCurrency === strtoupper((string) $order->get_currency());
    }

    /**
     * @param array<string, mixed> $event
     */
    public static function isSafeToComplete($order, array $event)
    {
        $savedAmount = self::meta($order, '_paymos_invoice_amount');
        $savedCurrency = strtoupper(self::meta($order, '_paymos_invoice_currency'));

        if ($savedAmount === '' || $savedCurrency === '') {
            return true;
        }

        return AmountGuard::isSafeToComplete(
            $savedAmount,
            $savedCurrency,
            self::formatAmount($order->get_total()),
            (string) $order->get_currency(),
            self::eventOrderAmount($event),
            self::eventOrderCurrency($event)
        );
    }

    /**
     * @param array<string, mixed> $event
     */
    public static function mismatchNote($order, array $event)
    {
        $savedAmount = self::meta($order, '_paymos_invoice_amount');
        $savedCurrency = strtoupper(self::meta($order, '_paymos_invoice_currency'));
        $currentAmount = self::formatAmount($order->get_total());
        $currentCurrency = strtoupper((string) $order->get_currency());
        $eventAmount = self::eventOrderAmount($event);
        $eventCurrency = self::eventOrderCurrency($event);

        return AmountGuard::mismatchSummary(
            $savedAmount,
            $savedCurrency,
            $currentAmount,
            $currentCurrency,
            $eventAmount === '' ? '' : self::formatAmount($eventAmount),
            $eventCurrency
        );
    }

    public static function formatAmount($amount)
    {
        if (function_exists('wc_format_decimal')) {
            return wc_format_decimal($amount, 2, false);
        }

        return number_format((float) $amount, 2, '.', '');
    }

    private static function meta($order, $key)
    {
        if (!method_exists($order, 'get_meta')) {
            return '';
        }

        return (string) $order->get_meta($key, true);
    }

    /**
     * @param array<string, mixed> $event
     */
    private static function eventOrderAmount(array $event)
    {
        if (isset($event['data']['order']['amount']) && is_scalar($event['data']['order']['amount'])) {
            return (string) $event['data']['order']['amount'];
        }

        return '';
    }

    /**
     * @param array<string, mixed> $event
     */
    private static function eventOrderCurrency(array $event)
    {
        if (isset($event['data']['order']['currency']) && is_scalar($event['data']['order']['currency'])) {
            return (string) $event['data']['order']['currency'];
        }

        return '';
    }
}
