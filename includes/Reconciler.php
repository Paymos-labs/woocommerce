<?php

declare(strict_types=1);

namespace PaymosWooCommerce;

use Paymos\Plugin\AmountGuard;
use Paymos\Plugin\StatusMapper;
use Paymos\Plugin\WordPressOrderLock;

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
        if (function_exists('wp_unschedule_hook')) {
            wp_unschedule_hook(self::HOOK);
        }
    }

    public static function run()
    {
        if (!function_exists('wc_get_orders')) {
            return 0;
        }
        $count = 0;
        foreach (array('waiting' => array('pending', 'on-hold'), 'closed' => array('failed', 'cancelled')) as $window => $statuses) {
            $key = 'paymos_woocommerce_reconcile_' . $window . '_cursor';
            $cursor = max(0, (int) get_option($key, 0));
            $orders = self::scanWindow($statuses, $cursor);
            if (!$orders && $cursor > 0) {
                update_option($key, 0, false);
                $orders = self::scanWindow($statuses, 0);
            }
            foreach ($orders as $order) {
                // Advance even when missing an invoice, final, locked or the API is down.
                update_option($key, (int) $order->get_id(), false);
                $count += self::reconcile_orders(array($order));
            }
        }
        return $count;
    }

    private static function scanWindow(array $statuses, $cursor)
    {
        global $wpdb;
        // Woo 7.9+ exposes HPOS clauses; the CPT store translates custom order vars
        // using its query hook before WP_Query's posts_where. Both restrict BEFORE LIMIT.
        $hpos = static function ($clauses, $query, $args) use ($cursor, $wpdb) {
            if (isset($args['paymos_after_id']) && (int) $args['paymos_after_id'] === $cursor) {
                $clauses['where'] .= $wpdb->prepare(' AND %i.id > %d', $wpdb->prefix . 'wc_orders', $cursor);
            }
            return $clauses;
        };
        $cpt = static function ($args, $queryVars) {
            if (isset($queryVars['paymos_after_id'])) {
                $args['paymos_after_id'] = (int) $queryVars['paymos_after_id'];
                $args['suppress_filters'] = false;
            }
            return $args;
        };
        $posts = static function ($where, $query) use ($wpdb, $cursor) {
            if ($query->get('paymos_after_id', null) !== null && (int) $query->get('paymos_after_id') === $cursor) {
                $where .= $wpdb->prepare(' AND %i.ID > %d', $wpdb->posts, $cursor);
            }
            return $where;
        };
        add_filter('woocommerce_orders_table_query_clauses', $hpos, 10, 3);
        add_filter('woocommerce_order_data_store_cpt_get_orders_query', $cpt, 10, 2);
        add_filter('posts_where', $posts, 10, 2);
        try {
            $orders = wc_get_orders(array('limit' => 50, 'status' => $statuses, 'payment_method' => 'paymos', 'return' => 'objects', 'orderby' => 'ID', 'order' => 'ASC', 'paymos_after_id' => $cursor));
            return is_array($orders) ? $orders : array();
        } finally {
            remove_filter('woocommerce_orders_table_query_clauses', $hpos, 10);
            remove_filter('woocommerce_order_data_store_cpt_get_orders_query', $cpt, 10);
            remove_filter('posts_where', $posts, 10);
        }
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

            $lock = new WordPressOrderLock('woocommerce', $order->get_id());
            if (!$lock->acquire()) {
                continue;
            }
            try {
                $order = FreshOrder::load($order->get_id(), $lock);
                if (!$order || $order->is_paid()) {
                    continue;
                }
                $invoiceId = self::orderMeta($order, '_paymos_invoice_id');
                if ($invoiceId === '' && self::orderMeta($order, '_paymos_creation_intent') !== 'pending') {
                    continue;
                }

                // A final Paymos status never changes again (the server refuses every
                // transition out of it), so there is nothing to fetch — and applying it
                // again every 10 minutes only stacked up identical order notes.
                $lastStatus = self::orderMeta($order, '_paymos_last_status');
                if (self::isFinalStatus($lastStatus) && !in_array($lastStatus, array('paid', 'paid_over'), true)) {
                    continue;
                }

                $environment = self::orderMeta($order, '_paymos_environment');
                if ($environment === '') {
                    $environment = Config::mode();
                }

                try {
                    if (self::orderMeta($order, '_paymos_creation_intent') === 'pending') {
                        CreationIntent::recover($order, $lock, self::client($environment, $clientFactory));
                        $invoiceId = self::orderMeta($order, '_paymos_invoice_id');
                    }
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
                    if ($status !== '' && $status === $lastStatus && !in_array($status, array('paid', 'paid_over'), true)) {
                        continue;
                    }

                    $event = self::eventFromInvoice($invoice, $now);
                    $beforePaid = method_exists($order, 'is_paid') ? (bool) $order->is_paid() : false;
                    $lock->assertOwned();
                    $mapper->apply($order, $event, true, $lock);
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
            } finally {
                $lock->release();
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

        $invoiceId = self::field($invoice, array('invoice_id'));
        $isTest = isset($invoice['is_test']) ? $invoice['is_test'] : null;
        return in_array(self::orderMeta($order, '_paymos_environment'), array('live', 'sandbox'), true)
            && self::orderMeta($order, '_paymos_invoice_id') !== ''
            && self::orderMeta($order, '_paymos_invoice_id') === $invoiceId
            && self::orderMeta($order, '_paymos_project_id') !== ''
            && self::orderMeta($order, '_paymos_project_id') === $projectId
            && self::orderMeta($order, '_paymos_external_order_id') !== ''
            && self::orderMeta($order, '_paymos_external_order_id') === $externalOrderId
            && AmountGuard::amountsEqual(self::orderMeta($order, '_paymos_invoice_amount'), $amount)
            && $currency !== '' && strtoupper(self::orderMeta($order, '_paymos_invoice_currency')) === strtoupper($currency)
            && is_bool($isTest) && $isTest === (self::orderMeta($order, '_paymos_environment') === 'sandbox');
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
                return $status === 'paid_over' ? 'invoice.paid_over' : 'invoice.paid';
            case StatusMapper::ACTION_FAIL_ORDER:
                return 'invoice.underpaid';
            case StatusMapper::ACTION_CANCEL_ORDER:
                return $status === 'expired' ? 'invoice.expired' : 'invoice.cancelled';
        }

        return 'invoice.updated';
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
