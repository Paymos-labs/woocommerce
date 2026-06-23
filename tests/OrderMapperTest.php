<?php

declare(strict_types=1);

use PaymosWooCommerce\OrderMapper;

final class FakeOrder
{
    /** @var bool */
    public $paid = false;

    /** @var array<int, array<int, string>> */
    public $statusUpdates = array();

    /** @var array<int, string> */
    public $notes = array();

    /** @var string */
    public $transactionId = '';

    /** @var array<string, mixed> */
    public $meta = array();

    /** @var string */
    private $total;

    /** @var string */
    private $currency;

    public function __construct($total = '100.00', $currency = 'USD')
    {
        $this->total = (string) $total;
        $this->currency = (string) $currency;
    }

    public function is_paid()
    {
        return $this->paid;
    }

    public function update_status($status, $note)
    {
        $this->statusUpdates[] = array($status, $note);
    }

    public function payment_complete($transactionId = '')
    {
        $this->paid = true;
        $this->transactionId = (string) $transactionId;
    }

    public function add_order_note($note)
    {
        $this->notes[] = (string) $note;
    }

    public function get_total()
    {
        return $this->total;
    }

    public function get_currency()
    {
        return $this->currency;
    }

    public function update_meta_data($key, $value)
    {
        $this->meta[(string) $key] = $value;
    }

    public function get_meta($key, $single = true)
    {
        return array_key_exists((string) $key, $this->meta) ? $this->meta[(string) $key] : '';
    }

    public function save()
    {
    }
}

function test_order_mapper_completes_paid_invoice()
{
    $order = new FakeOrder();
    $order->update_meta_data('_paymos_invoice_amount', '100.00');
    $order->update_meta_data('_paymos_invoice_currency', 'USD');
    $mapper = new OrderMapper();

    $mapper->apply($order, array(
        'event_id' => 'evt_123',
        'event_type' => 'invoice.paid',
        'occurred_at' => 1777975200,
        'data' => array(
            'invoice_id' => 'inv_123',
            'status' => 'paid',
            'order' => array('amount' => '100.00', 'currency' => 'USD'),
            'transfers' => array(
                array('tx_hash' => '0xabc', 'status' => 'confirmed'),
            ),
        ),
    ));

    assertTrueValue($order->paid, 'invoice.paid must complete Woo order.');
    assertSameValue('0xabc', $order->transactionId, 'invoice.paid must use tx hash as transaction id when present.');
    assertSameValue('Paymos payment completed.', $order->notes[0], 'invoice.paid must add a completion order note.');
    assertSameValue('invoice.paid', $order->meta['_paymos_last_event_type'], 'mapper must store last Paymos event type.');
    assertSameValue('evt_123', $order->meta['_paymos_last_event_id'], 'mapper must store last Paymos event id.');
    assertSameValue(gmdate('c', 1777975200), $order->meta['_paymos_last_event_at'], 'mapper must store Paymos event occurrence time as ISO-8601.');
    assertSameValue('paid', $order->meta['_paymos_last_status'], 'mapper must store last Paymos status.');
}

function test_order_mapper_uses_payment_transfers_for_transaction_id()
{
    $order = new FakeOrder();
    $order->update_meta_data('_paymos_invoice_amount', '100.00');
    $order->update_meta_data('_paymos_invoice_currency', 'USD');
    $mapper = new OrderMapper();

    $mapper->apply($order, array(
        'event_id' => 'evt_payment_transfer',
        'event_type' => 'invoice.paid',
        'data' => array(
            'invoice_id' => 'inv_123',
            'status' => 'paid',
            'order' => array('amount' => '100.00', 'currency' => 'USD'),
            'payment' => array(
                'transfers' => array(
                    array('tx_hash' => '0xconfirming', 'status' => 'confirming'),
                    array('tx_hash' => '0xconfirmed', 'status' => 'confirmed', 'explorer_url' => 'https://etherscan.io/tx/0xconfirmed'),
                ),
            ),
        ),
    ));

    assertTrueValue($order->paid, 'invoice.paid must complete Woo order.');
    assertSameValue('0xconfirmed', $order->transactionId, 'transaction id must come from data.payment.transfers[].');
    assertSameValue('0xconfirmed', $order->meta['_paymos_tx_hash'], 'mapper must persist the confirmed tx hash as order meta.');
    assertSameValue('https://etherscan.io/tx/0xconfirmed', $order->meta['_paymos_explorer_url'], 'mapper must persist the confirmed transfer explorer url.');
}

function test_order_mapper_does_not_complete_when_order_amount_changed()
{
    $order = new FakeOrder('120.00', 'USD');
    $order->update_meta_data('_paymos_invoice_amount', '100.00');
    $order->update_meta_data('_paymos_invoice_currency', 'USD');
    $mapper = new OrderMapper();

    $mapper->apply($order, array(
        'event_id' => 'evt_changed_amount',
        'event_type' => 'invoice.paid',
        'occurred_at' => 1777975200,
        'data' => array(
            'invoice_id' => 'inv_123',
            'status' => 'paid',
            'order' => array('amount' => '100.00', 'currency' => 'USD'),
            'transfers' => array(array('tx_hash' => '0xabc', 'status' => 'confirmed')),
        ),
    ));

    assertSameValue(false, $order->paid, 'invoice.paid must not complete Woo order when current order total changed.');
    assertSameValue('on-hold', $order->statusUpdates[0][0], 'amount mismatch must keep order on hold.');
    assertSameValue('yes', $order->meta['_paymos_amount_mismatch'], 'amount mismatch must be visible in order meta.');
}

function test_order_mapper_does_not_complete_when_invoice_currency_differs()
{
    $order = new FakeOrder('100.00', 'USD');
    $order->update_meta_data('_paymos_invoice_amount', '100.00');
    $order->update_meta_data('_paymos_invoice_currency', 'USD');
    $mapper = new OrderMapper();

    $mapper->apply($order, array(
        'event_id' => 'evt_changed_currency',
        'event_type' => 'invoice.paid',
        'occurred_at' => 1777975200,
        'data' => array(
            'invoice_id' => 'inv_123',
            'status' => 'paid',
            'order' => array('amount' => '100.00', 'currency' => 'EUR'),
            'transfers' => array(array('tx_hash' => '0xabc', 'status' => 'confirmed')),
        ),
    ));

    assertSameValue(false, $order->paid, 'invoice.paid must not complete Woo order when paid invoice currency differs.');
    assertSameValue('on-hold', $order->statusUpdates[0][0], 'currency mismatch must keep order on hold.');
    assertSameValue('yes', $order->meta['_paymos_amount_mismatch'], 'currency mismatch must be visible in order meta.');
}

function test_order_mapper_marks_confirming_on_hold()
{
    $order = new FakeOrder();
    $mapper = new OrderMapper();

    $mapper->apply($order, array('event_type' => 'invoice.confirming', 'data' => array()));

    assertSameValue('on-hold', $order->statusUpdates[0][0], 'invoice.confirming must keep order on hold.');
}

function test_order_mapper_cancels_expired_invoice()
{
    $order = new FakeOrder();
    $mapper = new OrderMapper();

    $mapper->apply($order, array('event_type' => 'invoice.expired', 'data' => array()));

    assertSameValue('cancelled', $order->statusUpdates[0][0], 'invoice.expired must cancel Woo order.');
}

function test_order_mapper_does_not_roll_back_paid_order_on_late_cancel_event()
{
    $order = new FakeOrder();
    $order->paid = true;
    $mapper = new OrderMapper();

    $mapper->apply($order, array(
        'event_id' => 'evt_late_cancel',
        'event_type' => 'invoice.cancelled',
        'data' => array('status' => 'cancelled'),
    ));

    assertSameValue(array(), $order->statusUpdates, 'late cancel webhook must not roll back an already paid Woo order.');
    assertSameValue('invoice.cancelled', $order->meta['_paymos_last_event_type'], 'late event must still be recorded for audit.');
}
