<?php

declare(strict_types=1);

namespace PaymosWooCommerce;

use Paymos\Plugin\AmountGuard;
use Paymos\Plugin\WordPressOrderLock;

defined('ABSPATH') || exit;

/** Durable invoice POST identity, persisted before the network call. */
final class CreationIntent
{
    public static function persist($order, array $payload, $environment, WordPressOrderLock $lock)
    {
        $lock->assertOwned();
        self::validatePayload($payload);
        if (!in_array($environment, array('sandbox', 'live'), true)) {
            throw new \RuntimeException('Paymos invoice intent environment is invalid.');
        }
        $order->update_meta_data('_paymos_creation_payload', $payload);
        $order->update_meta_data('_paymos_external_order_id', $payload['external_order_id']);
        $order->update_meta_data('_paymos_project_id', $payload['project_id']);
        $order->update_meta_data('_paymos_environment', $environment);
        $order->update_meta_data('_paymos_client_id', isset($payload['client_id']) ? $payload['client_id'] : '');
        OrderAmountGuard::capture($order, $payload['amount'], $payload['currency']);
        $order->update_meta_data('_paymos_invoice_id', '');
        $order->update_meta_data('_paymos_payment_url', '');
        $order->update_meta_data('_paymos_last_status', '');
        $order->update_meta_data('_paymos_expires_at', '');
        $order->update_meta_data('_paymos_applied_paid_invoice_id', '');
        $order->update_meta_data('_paymos_creation_intent', 'pending');
        self::save($order, $lock);
    }

    public static function payload($order)
    {
        $payload = $order->get_meta('_paymos_creation_payload', true);
        if (!is_array($payload)) {
            throw new \RuntimeException('Paymos pending invoice intent is unavailable.');
        }
        self::validatePayload($payload);
        if (!in_array((string) $order->get_meta('_paymos_environment', true), array('sandbox', 'live'), true)) {
            throw new \RuntimeException('Paymos pending invoice environment is unavailable.');
        }
        if ((isset($payload['client_id']) ? $payload['client_id'] : '') !== (string) $order->get_meta('_paymos_client_id', true)) {
            throw new \RuntimeException('Paymos pending invoice client differs.');
        }
        foreach (array('project_id' => '_paymos_project_id', 'external_order_id' => '_paymos_external_order_id', 'currency' => '_paymos_invoice_currency') as $field => $meta) {
            if ($payload[$field] !== (string) $order->get_meta($meta, true)) {
                throw new \RuntimeException('Paymos pending invoice intent snapshot differs.');
            }
        }
        if (!AmountGuard::amountsEqual($payload['amount'], $order->get_meta('_paymos_invoice_amount', true))) {
            throw new \RuntimeException('Paymos pending invoice amount differs.');
        }
        return $payload;
    }

    public static function matches($order, array $payload, $environment)
    {
        $saved = self::payload($order);
        return (string) $order->get_meta('_paymos_environment', true) === $environment
            && $saved['project_id'] === $payload['project_id']
            && $saved['currency'] === $payload['currency']
            && AmountGuard::amountsEqual($saved['amount'], $payload['amount'])
            && (isset($saved['client_id']) ? $saved['client_id'] : '') === (isset($payload['client_id']) ? $payload['client_id'] : '');
    }

    public static function recover($order, WordPressOrderLock $lock, $client)
    {
        if ((string) $order->get_meta('_paymos_creation_intent', true) !== 'pending') {
            throw new \RuntimeException('Paymos invoice intent is not pending.');
        }
        $invoice = $client->invoices()->create(self::payload($order));
        $lock->assertOwned();
        self::bind($order, $invoice, $lock);
        return $invoice;
    }

    public static function bind($order, array $invoice, WordPressOrderLock $lock)
    {
        $id = isset($invoice['invoice_id']) && is_string($invoice['invoice_id']) ? trim($invoice['invoice_id']) : '';
        if ($id === '') {
            throw new \RuntimeException('Paymos invoice creation response has no invoice ID.');
        }
        $existing = (string) $order->get_meta('_paymos_invoice_id', true);
        if ($existing !== '' && $existing !== $id) {
            throw new \RuntimeException('Paymos invoice creation identity differs from binding.');
        }
        $lock->assertOwned();
        // First durably save the independent API ID. Clear pending only afterward.
        $order->update_meta_data('_paymos_invoice_id', $id);
        self::save($order, $lock);
        $order->update_meta_data('_paymos_creation_intent', 'bound');
        self::save($order, $lock);
    }

    private static function validatePayload(array $payload)
    {
        foreach (array('project_id', 'external_order_id', 'amount', 'currency') as $field) {
            if (!isset($payload[$field]) || !is_string($payload[$field]) || trim($payload[$field]) === '') {
                throw new \RuntimeException('Paymos invoice intent is incomplete.');
            }
        }
        $amount = AmountGuard::normalizeDecimal($payload['amount']);
        if ($amount === null || $amount === '0' || $amount[0] === '-') {
            throw new \RuntimeException('Paymos invoice intent amount is invalid.');
        }
        if (isset($payload['client_id']) && (!is_string($payload['client_id']) || trim($payload['client_id']) === '')) {
            throw new \RuntimeException('Paymos invoice intent client ID is invalid.');
        }
    }

    private static function save($order, WordPressOrderLock $lock)
    {
        $lock->assertOwned();
        if (!$order->save()) {
            throw new \RuntimeException('Woo invoice intent save failed.');
        }
        $lock->assertOwned();
    }
}
