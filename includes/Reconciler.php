<?php

declare(strict_types=1);

namespace PaymosWooCommerce;

use Paymos\Plugin\AmountGuard;
use Paymos\Plugin\StatusMapper;

defined('ABSPATH') || exit;

final class Reconciler
{
    public const HOOK = 'paymos_reconcile_orders';
    public const INTERVAL = 'paymos_ten_minutes';

    public static function cron_schedules($schedules)
    {
        if (!is_array($schedules)) {
            $schedules = array();
        }

        $schedules[self::INTERVAL] = array(
            'interval' => 600,
            'display' => __('Every 10 minutes', 'paymos-for-woocommerce'),
        );

        return $schedules;
    }

    public static function maybe_schedule()
    {
        if (!function_exists('wp_next_scheduled') || !function_exists('wp_schedule_event')) {
            return;
        }

        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + 60, self::INTERVAL, self::HOOK);
        }
    }

    public static function unschedule()
    {
        if (!function_exists('wp_next_scheduled') || !function_exists('wp_unschedule_event')) {
            return;
        }

        $timestamp = wp_next_scheduled(self::HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::HOOK);
        }
    }

    public static function run()
    {
        if (!function_exists('wc_get_orders')) {
            return 0;
        }

        // Two windows, not one "newest 50" across every status: a burst of
        // cancellations must not push an order still waiting for its payment out
        // of reach. Failed and cancelled orders stay in — a late payment on an
        // invoice that is still open is exactly what the reconciler exists for —
        // but those whose invoice is already final are skipped below.
        $orders = array();
        foreach (array(array('pending', 'on-hold'), array('failed', 'cancelled')) as $statuses) {
            $batch = wc_get_orders(array(
                'limit' => 50,
                'status' => $statuses,
                'payment_method' => 'paymos',
                'return' => 'objects',
            ));
            if (is_array($batch)) {
                $orders = array_merge($orders, $batch);
            }
        }

        return self::reconcile_orders($orders);
    }

    /**
     * @param array<int, object> $orders
     * @param callable|null $clientFactory
     */
    public static function reconcile_orders(array $orders, $clientFactory = null, $now = null)
    {
        $count = 0;
        $mapper = new OrderMapper();
        $now = $now === null ? time() : (int) $now;

        foreach ($orders as $order) {
            if (!is_object($order) || (method_exists($order, 'is_paid') && $order->is_paid())) {
                continue;
            }

            $invoiceId = self::orderMeta($order, '_paymos_invoice_id');
            if ($invoiceId === '') {
                continue;
            }

            // A final Paymos status never changes again (the server refuses every
            // transition out of it), so there is nothing to fetch — and applying it
            // again every 10 minutes only stacked up identical order notes.
            $lastStatus = self::orderMeta($order, '_paymos_last_status');
            if (self::isFinalStatus($lastStatus)) {
                continue;
            }

            $environment = self::orderMeta($order, '_paymos_environment');
            if ($environment === '') {
                $environment = Config::mode();
            }

            try {
                $invoice = self::client($environment, $clientFactory)->invoices()->get($invoiceId);
                if (!self::matchesOrderSnapshot($order, $invoice)) {
                    Logger::error('Paymos reconcile skipped invoice snapshot mismatch.', array(
                        'invoice_id' => $invoiceId,
                        'environment' => $environment,
                    ));
                    continue;
                }

                // Same status as the last one recorded: nothing new to apply.
                $status = self::field($invoice, array('status'));
                if ($status !== '' && $status === $lastStatus) {
                    continue;
                }

                $event = self::eventFromInvoice($invoice, $now);
                $beforePaid = method_exists($order, 'is_paid') ? (bool) $order->is_paid() : false;
                $mapper->apply($order, $event, true);
                $afterPaid = method_exists($order, 'is_paid') ? (bool) $order->is_paid() : false;
                if (!$beforePaid && $afterPaid) {
                    $count++;
                }
            } catch (\Exception $e) {
                Logger::error('Paymos reconcile failed: ' . $e->getMessage(), array(
                    'invoice_id' => $invoiceId,
                    'environment' => $environment,
                ));
            }
        }

        return $count;
    }

    private static function client($environment, $clientFactory = null)
    {
        if ($clientFactory !== null) {
            return call_user_func($clientFactory, $environment);
        }

        $config = Config::environment_config($environment);
        foreach (array('api_key', 'api_secret', 'base_url') as $required) {
            if (!isset($config[$required]) || !is_scalar($config[$required]) || trim((string) $config[$required]) === '') {
                throw new \RuntimeException('Paymos credentials are incomplete.');
            }
        }

        return ClientFactory::create($config);
    }

    /**
     * @param array<string, mixed> $invoice
     */
    private static function matchesOrderSnapshot($order, array $invoice)
    {
        $projectId = self::field($invoice, array('project_id'));
        $externalOrderId = self::field($invoice, array('order', 'external_id'));
        $amount = self::field($invoice, array('order', 'amount'));
        $currency = self::field($invoice, array('order', 'currency'));

        return self::matchesIfPresent(self::orderMeta($order, '_paymos_project_id'), $projectId)
            && self::matchesIfPresent(self::orderMeta($order, '_paymos_external_order_id'), $externalOrderId)
            && self::amountMatchesIfPresent(self::orderMeta($order, '_paymos_invoice_amount'), $amount)
            && self::matchesIfPresent(strtoupper(self::orderMeta($order, '_paymos_invoice_currency')), strtoupper($currency));
    }

    /**
     * @param array<string, mixed> $invoice
     * @return array<string, mixed>
     */
    private static function eventFromInvoice(array $invoice, $now)
    {
        $status = self::field($invoice, array('status'));
        $invoiceId = self::field($invoice, array('invoice_id'));

        return array(
            'event_id' => 'reconcile_' . $invoiceId . '_' . $status,
            'event_type' => self::eventTypeForStatus($status),
            'occurred_at' => (int) $now,
            'data' => $invoice,
        );
    }

    private static function eventTypeForStatus($status)
    {
        switch (StatusMapper::invoiceAction('', $status)) {
            case StatusMapper::ACTION_CONFIRMING:
                return 'invoice.confirming';
            case StatusMapper::ACTION_AWAITING_PAYMENT:
                return $status === 'awaiting_payment' ? 'invoice.awaiting_payment' : 'invoice.underpaid_waiting';
            case StatusMapper::ACTION_PAYMENT_COMPLETE:
                return 'invoice.paid';
            case StatusMapper::ACTION_FAIL_ORDER:
                return 'invoice.underpaid';
            case StatusMapper::ACTION_CANCEL_ORDER:
                return $status === 'expired' ? 'invoice.expired' : 'invoice.cancelled';
        }

        return 'invoice.updated';
    }

    private static function matchesIfPresent($expected, $actual)
    {
        $expected = trim((string) $expected);
        $actual = trim((string) $actual);
        return $expected === '' || $actual === '' || $expected === $actual;
    }

    /**
     * Decimal-safe: the snapshot holds two decimals ("2500.00") while the server
     * echoes a fiat amount at the currency's own scale ("2500" for JPY) — the same
     * amount, which a string compare skipped on every run.
     */
    private static function amountMatchesIfPresent($expected, $actual)
    {
        $expected = trim((string) $expected);
        $actual = trim((string) $actual);
        return $expected === '' || $actual === '' || AmountGuard::amountsEqual($expected, $actual);
    }

    private static function isFinalStatus($status)
    {
        return in_array(StatusMapper::invoiceAction('', (string) $status), array(
            StatusMapper::ACTION_PAYMENT_COMPLETE,
            StatusMapper::ACTION_FAIL_ORDER,
            StatusMapper::ACTION_CANCEL_ORDER,
        ), true);
    }

    private static function orderMeta($order, $key)
    {
        if (!method_exists($order, 'get_meta')) {
            return '';
        }

        return (string) $order->get_meta($key, true);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $path
     */
    private static function field(array $payload, array $path)
    {
        $current = $payload;
        foreach ($path as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return '';
            }

            $current = $current[$segment];
        }

        return is_scalar($current) ? (string) $current : '';
    }
}
