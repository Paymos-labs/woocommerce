<?php

declare(strict_types=1);

namespace PaymosWooCommerce;

use Paymos\Plugin\StatusMapper;
use Paymos\Plugin\WordPressOrderLock;

defined('ABSPATH') || exit;

final class OrderMapper
{
    /**
     * @param \WC_Order $order
     * @param array<string, mixed> $event
     * @param bool $fromReconcile True when the "event" was built by the reconciler
     *                            from a fetched invoice rather than delivered as a
     *                            webhook (see awaitingPaymentNote()).
     */
    public function apply($order, array $event, $fromReconcile = false, $ownedLock = null)
    {
        if ($ownedLock !== null) {
            $ownedLock->assertOwned();
            return $this->applyOwned($order, $event, $fromReconcile);
        }
        $lock = new WordPressOrderLock('woocommerce', $order->get_id());
        if (!$lock->acquire()) {
            throw new \RuntimeException('Paymos Woo order is busy.');
        }
        try {
            $fresh = FreshOrder::load($order->get_id(), $lock);
            if (!$fresh) {
                throw new \RuntimeException('Woo order was not found.');
            }
            $lock->assertOwned();
            return $this->applyOwned($fresh, $event, $fromReconcile);
        } finally {
            $lock->release();
        }
    }

    private function applyOwned($order, array $event, $fromReconcile)
    {
        $eventType = isset($event['event_type']) ? (string) $event['event_type'] : '';
        // Read before recordEvent(): the status this order already reached for
        // its current invoice.
        $previousStatus = method_exists($order, 'get_meta') ? (string) $order->get_meta('_paymos_last_status', true) : '';
        if (in_array($order->get_status(), array('refunded'), true)) {
            return;
        }
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

        // invoice-late-payment: money crossed the invoice's total AFTER it had closed. The order keeps
        // its terminal decision — one native note, idempotent per invoice, decided BEFORE the
        // final-status guard below (a late event by definition arrives after a terminal status; the
        // redelivery guard must not eat it). No status, stock or payment action anywhere here.
        if ($action === StatusMapper::ACTION_NOTE_PAID_LATE) {
            $this->addPaidLateNote($order, $event);
            $this->save($order);
            return;
        }

        // An unapplied paid observation may retry completion after mismatch/native failure.
        // Other final decisions never accept an out-of-order state. An
        // event that arrives after one is an out-of-order redelivery. Guarding only
        // paid orders let a late underpaid_waiting pull a failed order back on
        // hold — and re-reserve its stock. The final status stays recorded.
        if (StatusMapper::isFinalStatus($previousStatus) && (!in_array($previousStatus, array('paid', 'paid_over'), true) || $action !== StatusMapper::ACTION_PAYMENT_COMPLETE)) {
            Logger::info('Paymos ignored an invoice status that arrived after a final one.', array(
                'final_status' => $previousStatus,
                'event_type' => $eventType,
            ));
            $this->save($order);
            return;
        }

        switch ($action) {
            case StatusMapper::ACTION_CONFIRMING:
                if (!$order->is_paid()) {
                    $this->transition($order, 'on-hold', __('Paymos payment is confirming.', 'paymos-for-woocommerce'));
                    $order->add_order_note(__('Paymos payment is confirming.', 'paymos-for-woocommerce'));
                }
                break;

            // Held for the same reason as confirming, but for a different cause, so it
            // must not borrow that wording: a short payment needs the customer to send
            // the rest, while "confirming" tells the merchant to simply wait.
            case StatusMapper::ACTION_AWAITING_PAYMENT:
                if (!$order->is_paid()) {
                    $note = $this->awaitingPaymentNote($event, $fromReconcile);
                    $this->transition($order, 'on-hold', $note);
                    $order->add_order_note($note);
                }
                break;

            case StatusMapper::ACTION_PAYMENT_COMPLETE:
                if ($order->is_paid()) {
                    return;
                }
                if (!OrderAmountGuard::isSafeToComplete($order, $event)) {
                    $order->update_meta_data('_paymos_amount_mismatch', 'yes');
                    $this->transition($order, 'on-hold', __('Paymos payment amount needs manual review.', 'paymos-for-woocommerce'));
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
                $completed = $order->payment_complete($transfer['tx_hash'] !== '' ? $transfer['tx_hash'] : $this->fallbackTransactionId($event));
                if ($completed !== true || !$order->is_paid()) {
                    throw new \RuntimeException('Woo payment completion failed.');
                }
                $this->recordStatus($order, $event);
                $order->update_meta_data('_paymos_applied_paid_invoice_id', $this->fallbackTransactionId($event));
                $order->add_order_note(__('Paymos payment completed.', 'paymos-for-woocommerce'));
                break;

            case StatusMapper::ACTION_FAIL_ORDER:
                $this->transition($order, 'failed', __('Paymos invoice was underpaid.', 'paymos-for-woocommerce'));
                $order->add_order_note(__('Paymos invoice was underpaid.', 'paymos-for-woocommerce'));
                break;

            case StatusMapper::ACTION_CANCEL_ORDER:
                $this->transition($order, 'cancelled', __('Paymos invoice was cancelled.', 'paymos-for-woocommerce'));
                $order->add_order_note(__('Paymos invoice was cancelled.', 'paymos-for-woocommerce'));
                break;
        }

        if ($action !== StatusMapper::ACTION_PAYMENT_COMPLETE) {
            $this->recordStatus($order, $event);
        }
        $this->save($order);
    }

    private function transition($order, $status, $note)
    {
        if ($order->update_status($status, $note) !== true || $order->get_status() !== $status) {
            throw new \RuntimeException('Woo order status transition failed.');
        }
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
    /**
     * ACTION_AWAITING_PAYMENT covers two very different situations: the customer paid
     * less than the invoice, or a payment that had been counted vanished in a chain
     * reorg. Only the first is the customer's to fix, so the note names the shortfall
     * whenever the server reported one.
     *
     * A webhook with no shortfall is the reorg case: the server sends
     * invoice.awaiting_payment for nothing else. The reconciler cannot tell — it sees
     * the same awaiting_payment for a buyer who picked a token and has not paid
     * yet — so there the note stays neutral.
     *
     * @param array<string, mixed> $event
     * @param bool $fromReconcile
     * @return string
     */
    private function awaitingPaymentNote(array $event, $fromReconcile = false)
    {
        $payment = isset($event['data']['payment']) && is_array($event['data']['payment'])
            ? $event['data']['payment']
            : array();
        $remaining = isset($payment['remaining']) && is_scalar($payment['remaining'])
            ? trim((string) $payment['remaining'])
            : '';
        $currency = isset($payment['currency']) && is_scalar($payment['currency'])
            ? trim((string) $payment['currency'])
            : '';

        if ($remaining === '' || !is_numeric($remaining) || (float) $remaining <= 0) {
            if ($fromReconcile) {
                return __('Awaiting Paymos payment.', 'paymos-for-woocommerce');
            }

            return __('Paymos is waiting for the payment again — a confirmed transfer was rolled back on-chain.', 'paymos-for-woocommerce');
        }

        return sprintf(
            /* translators: 1: outstanding amount, 2: token symbol such as USDT */
            __('Paymos received only part of the payment. %1$s %2$s is still outstanding — the order stays on hold until the customer sends the rest.', 'paymos-for-woocommerce'),
            $remaining,
            $currency !== '' ? $currency : __('in the invoice currency', 'paymos-for-woocommerce')
        );
    }

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

    }

    /**
     * Recorded apart from the rest of the event, and only for an event that is
     * actually applied: a stale one must not overwrite the final status the
     * guards above rely on.
     *
     * @param array<string, mixed> $event
     */
    private function recordStatus($order, array $event)
    {
        if (isset($event['data']['status']) && is_scalar($event['data']['status'])) {
            $order->update_meta_data('_paymos_last_status', (string) $event['data']['status']);
        }
    }

    private function save($order)
    {
        if (method_exists($order, 'save')) {
            if (!$order->save()) {
                throw new \RuntimeException('Woo order save failed.');
            }
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
     * invoice-late-payment: the one allowed effect of a late payment on the order — a native note.
     * Idempotent per invoice: the marker lands only after the note was actually accepted, so a
     * sequential duplicate event, a redelivery or a reordered pair never writes a second note.
     * The invoice id rides the marker because one order can outlive several invoices.
     *
     * @param \WC_Order $order
     * @param array<string, mixed> $event
     */
    private function addPaidLateNote($order, array $event)
    {
        $invoiceId = isset($event['data']['invoice_id']) && is_scalar($event['data']['invoice_id'])
            ? (string) $event['data']['invoice_id']
            : '';
        $marker = '_paymos_paid_late_note' . ($invoiceId !== '' ? ':' . $invoiceId : '');

        $existing = method_exists($order, 'get_meta') ? (string) $order->get_meta($marker, true) : '';
        if ($existing !== '') {
            Logger::info('Paymos paid-late note already recorded for this invoice.', array(
                'invoice_id' => $invoiceId,
            ));
            return;
        }

        $note = __('Paymos: the customer finished paying for this order after the invoice closed. Check stock, then fulfil the order manually or refund the payment.', 'paymos-for-woocommerce');
        $noteId = $order->add_order_note($note);
        if ($noteId === false || $noteId === 0) {
            // Note rejected (store error): no marker — the next delivery retries, nothing is blessed.
            Logger::info('Paymos paid-late note was rejected; no marker recorded.', array(
                'invoice_id' => $invoiceId,
            ));
            return;
        }

        $order->update_meta_data($marker, isset($event['event_id']) && is_scalar($event['event_id'])
            ? (string) $event['event_id']
            : '1');
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
