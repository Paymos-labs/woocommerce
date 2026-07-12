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
            $order->add_order_note(__('Paymos ignored a stale invoice status after payment completed.', 'paymos-for-woocommerce'));
            $this->save($order);
            return;
        }

        switch ($action) {
            case StatusMapper::ACTION_CONFIRMING:
            case StatusMapper::ACTION_AWAITING_PAYMENT:
                if (!$order->is_paid()) {
                    $order->update_status('on-hold', __('Paymos payment is confirming.', 'paymos-for-woocommerce'));
                    $order->add_order_note(__('Paymos payment is confirming.', 'paymos-for-woocommerce'));
                }
                break;

            case StatusMapper::ACTION_PAYMENT_COMPLETE:
                if (!OrderAmountGuard::isSafeToComplete($order, $event)) {
                    $order->update_meta_data('_paymos_amount_mismatch', 'yes');
                    $order->update_status('on-hold', __('Paymos payment amount needs manual review.', 'paymos-for-woocommerce'));
                    $order->add_order_note(OrderAmountGuard::mismatchNote($order, $event));
                    $this->save($order);
                    break;
                }

                $order->update_meta_data('_paymos_amount_mismatch', 'no');
                $transfer = $this->selectedTransfer($event);
                if ($transfer['tx_hash'] !== '') {
                    $order->update_meta_data('_paymos_tx_hash', $transfer['tx_hash']);
                }
                if ($transfer['explorer_url'] !== '') {
                    $order->update_meta_data('_paymos_explorer_url', $transfer['explorer_url']);
                }
                $order->payment_complete($transfer['tx_hash'] !== '' ? $transfer['tx_hash'] : $this->fallbackTransactionId($event));
                $order->add_order_note(__('Paymos payment completed.', 'paymos-for-woocommerce'));
                break;

            case StatusMapper::ACTION_FAIL_ORDER:
                $order->update_status('failed', __('Paymos invoice was underpaid.', 'paymos-for-woocommerce'));
                $order->add_order_note(__('Paymos invoice was underpaid.', 'paymos-for-woocommerce'));
                break;

            case StatusMapper::ACTION_CANCEL_ORDER:
                $order->update_status('cancelled', __('Paymos invoice was cancelled.', 'paymos-for-woocommerce'));
                $order->add_order_note(__('Paymos invoice was cancelled.', 'paymos-for-woocommerce'));
                break;
        }

        $this->save($order);
    }

    /**
     * Select the on-chain transfer whose hash + explorer link represent the
     * payment. Webhook payload (InvoiceStatusContract) carries transfers inside
     * data.payment.transfers[] — each entry has a string `tx_hash`, an
     * `explorer_url`, and a `status` of "confirming" | "confirmed". Prefer the
     * latest confirmed transfer; fall back to the latest of any status. Returns
     * empty strings when no transfers are present (sandbox-confirmed / simulated
     * payment, where the server omits transfers entirely).
     *
     * `data.transfers` (top-level) is read only as a defensive fallback for any
     * payload still queued from an older server build — the canonical location
     * is data.payment.transfers.
     *
     * @param array<string, mixed> $event
     * @return array{tx_hash: string, explorer_url: string}
     */
    private function selectedTransfer(array $event)
    {
        $transfers = null;
        if (isset($event['data']['payment']['transfers']) && is_array($event['data']['payment']['transfers'])) {
            $transfers = $event['data']['payment']['transfers'];
        } elseif (isset($event['data']['transfers']) && is_array($event['data']['transfers'])) {
            $transfers = $event['data']['transfers'];
        }

        $confirmed = null;
        $latest = null;
        if ($transfers !== null) {
            foreach ($transfers as $transfer) {
                if (!is_array($transfer)) {
                    continue;
                }
                if (!isset($transfer['tx_hash']) || !is_string($transfer['tx_hash']) || $transfer['tx_hash'] === '') {
                    continue;
                }
                $latest = $transfer;
                $status = isset($transfer['status']) && is_string($transfer['status'])
                    ? strtolower($transfer['status'])
                    : '';
                if ($status === 'confirmed') {
                    $confirmed = $transfer;
                }
            }
        }

        $chosen = $confirmed !== null ? $confirmed : $latest;
        if ($chosen === null) {
            return array('tx_hash' => '', 'explorer_url' => '');
        }

        return array(
            'tx_hash' => (string) $chosen['tx_hash'],
            'explorer_url' => isset($chosen['explorer_url']) && is_string($chosen['explorer_url'])
                ? $chosen['explorer_url']
                : '',
        );
    }

    /**
     * @param array<string, mixed> $event
     */
    private function fallbackTransactionId(array $event)
    {
        return isset($event['data']['invoice_id']) ? (string) $event['data']['invoice_id'] : '';
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
