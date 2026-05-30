<?php

declare(strict_types=1);

namespace PaymosWooCommerce;

use Paymos\Plugin\StatusMapper;

defined('ABSPATH') || exit;

final class OrderMapper
{
    /**
     * @param \WC_Order $order
     * @param array<string, mixed> $event
     */
    public function apply($order, array $event)
    {
        $eventType = isset($event['event_type']) ? (string) $event['event_type'] : '';
        $this->recordEvent($order, $event, $eventType);

        $status = isset($event['data']['status']) && is_scalar($event['data']['status'])
            ? (string) $event['data']['status']
            : null;

        $action = StatusMapper::invoiceAction($eventType, $status);
        if ($order->is_paid() && $this->wouldRollBackPaidOrder($action)) {
            $order->add_order_note(__('Paymos ignored a stale invoice status after payment completed.', 'paymos-woocommerce'));
            $this->save($order);
            return;
        }

        switch ($action) {
            case StatusMapper::ACTION_CONFIRMING:
            case StatusMapper::ACTION_AWAITING_PAYMENT:
                if (!$order->is_paid()) {
                    $order->update_status('on-hold', __('Paymos payment is confirming.', 'paymos-woocommerce'));
                    $order->add_order_note(__('Paymos payment is confirming.', 'paymos-woocommerce'));
                }
                break;

            case StatusMapper::ACTION_PAYMENT_COMPLETE:
                if (!OrderAmountGuard::isSafeToComplete($order, $event)) {
                    $order->update_meta_data('_paymos_amount_mismatch', 'yes');
                    $order->update_status('on-hold', __('Paymos payment amount needs manual review.', 'paymos-woocommerce'));
                    $order->add_order_note(OrderAmountGuard::mismatchNote($order, $event));
                    $this->save($order);
                    break;
                }

                $order->payment_complete($this->transactionId($event));
                $order->add_order_note(__('Paymos payment completed.', 'paymos-woocommerce'));
                break;

            case StatusMapper::ACTION_FAIL_ORDER:
                $order->update_status('failed', __('Paymos payment failed or expired.', 'paymos-woocommerce'));
                $order->add_order_note(__('Paymos invoice was underpaid.', 'paymos-woocommerce'));
                break;

            case StatusMapper::ACTION_CANCEL_ORDER:
                $order->update_status('cancelled', __('Paymos invoice was cancelled.', 'paymos-woocommerce'));
                $order->add_order_note(__('Paymos invoice was cancelled.', 'paymos-woocommerce'));
                break;

            default:
                $this->applyLegacyAction($order, $eventType);
        }

        $this->save($order);
    }

    /**
     * @param array<string, mixed> $event
     */
    private function transactionId(array $event)
    {
        // Webhook payload (InvoiceStatusContract) carries on-chain hashes inside
        // data.transfers[]. Pick the latest confirmed transfer for the WC order
        // transaction id; fall back to invoice id when no transfers are present
        // (e.g. sandbox-confirmed invoice with simulated payment).
        if (isset($event['data']['transfers']) && is_array($event['data']['transfers'])) {
            $confirmed = '';
            $latest = '';
            foreach ($event['data']['transfers'] as $transfer) {
                if (!is_array($transfer)) {
                    continue;
                }
                if (!isset($transfer['tx_hash']) || !is_string($transfer['tx_hash'])) {
                    continue;
                }
                $hash = $transfer['tx_hash'];
                $latest = $hash;
                $status = isset($transfer['status']) && is_string($transfer['status'])
                    ? strtolower($transfer['status'])
                    : '';
                if ($status === 'confirmed') {
                    $confirmed = $hash;
                }
            }
            if ($confirmed !== '') {
                return $confirmed;
            }
            if ($latest !== '') {
                return $latest;
            }
        }

        return isset($event['data']['invoice_id']) ? (string) $event['data']['invoice_id'] : '';
    }

    private function applyLegacyAction($order, $eventType)
    {
        $action = StatusMapper::paymentAction($eventType);
        if ($order->is_paid() && in_array($action, array(StatusMapper::ACTION_FAILED, StatusMapper::ACTION_CANCELLED), true)) {
            $order->add_order_note(__('Paymos ignored a stale invoice status after payment completed.', 'paymos-woocommerce'));
            return;
        }

        switch ($action) {
            case StatusMapper::ACTION_PROCESSING:
                if (!$order->is_paid()) {
                    $order->update_status('on-hold', __('Paymos payment is processing.', 'paymos-woocommerce'));
                    $order->add_order_note(__('Paymos payment is processing.', 'paymos-woocommerce'));
                }
                break;
            case StatusMapper::ACTION_FAILED:
                $order->update_status('failed', __('Paymos payment failed.', 'paymos-woocommerce'));
                $order->add_order_note(__('Paymos payment failed.', 'paymos-woocommerce'));
                break;
            case StatusMapper::ACTION_CANCELLED:
                $order->update_status('cancelled', __('Paymos invoice was cancelled.', 'paymos-woocommerce'));
                $order->add_order_note(__('Paymos invoice was cancelled.', 'paymos-woocommerce'));
                break;
        }
    }

    /**
     * @param array<string, mixed> $event
     */
    private function recordEvent($order, array $event, $eventType)
    {
        $order->update_meta_data('_paymos_last_event_type', $eventType);

        if (isset($event['event_id']) && is_scalar($event['event_id'])) {
            $order->update_meta_data('_paymos_last_event_id', (string) $event['event_id']);
        }

        // Paymos serializes timestamps as Unix seconds (int). Format as ISO-8601
        // UTC for human-readable display in the WooCommerce order admin.
        $ts = self::extractUnixSeconds($event, 'occurred_at');
        if ($ts === null) {
            $ts = self::extractUnixSeconds($event, 'created_at');
        }
        if ($ts !== null) {
            $order->update_meta_data('_paymos_last_event_at', gmdate('c', $ts));
        }

        if (isset($event['data']['status']) && is_scalar($event['data']['status'])) {
            $order->update_meta_data('_paymos_last_status', (string) $event['data']['status']);
        }
    }

    private function save($order)
    {
        if (method_exists($order, 'save')) {
            $order->save();
        }
    }

    private function wouldRollBackPaidOrder($action)
    {
        return in_array($action, array(
            StatusMapper::ACTION_CONFIRMING,
            StatusMapper::ACTION_AWAITING_PAYMENT,
            StatusMapper::ACTION_FAIL_ORDER,
            StatusMapper::ACTION_CANCEL_ORDER,
        ), true);
    }

    /**
     * Pull a Unix-seconds integer out of an event payload field. Tolerates
     * numeric strings just in case JSON parsers vary on int width handling.
     *
     * @param array<string, mixed> $event
     */
    private static function extractUnixSeconds(array $event, $key)
    {
        if (!isset($event[$key])) {
            return null;
        }
        $value = $event[$key];
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return (int) $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }
        return null;
    }
}
