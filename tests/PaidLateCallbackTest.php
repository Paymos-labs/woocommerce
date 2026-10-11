<?php

declare(strict_types=1);

use PaymosWooCommerce\OrderMapper;

// invoice-late-payment: a paid_late event on an invoice the order already holds a FINAL status for
// (expired/underpaid) produces exactly ONE native order note — never a status, stock or payment
// action — and a duplicate delivery (same invoice, later event id) stays silent. Old plugin with an
// already-final row: the note still lands, decided before the stale-after-final guard.

function paidLateEvent($eventId, $status = 'expired')
{
    return array(
        'event_id' => $eventId,
        'event_type' => 'invoice.paid_late',
        'occurred_at' => 1777978800,
        'data' => array(
            'invoice_id' => 'inv_late_1',
            'status' => $status,
            'paid_late_at' => 1777978800,
            'order' => array('amount' => '100.00', 'currency' => 'USD'),
        ),
    );
}

function finalExpiredOrder()
{
    $order = new FakeOrder();
    $order->update_meta_data('_paymos_invoice_amount', '100.00');
    $order->update_meta_data('_paymos_invoice_currency', 'USD');
    $order->update_meta_data('_paymos_last_status', 'expired');
    return $order;
}

function test_case_late_16_paid_late_on_cancelled_order_adds_one_note_and_changes_nothing()
{
    $order = finalExpiredOrder();
    $mapper = new OrderMapper();

    $mapper->apply($order, paidLateEvent('evt_late_1'));

    assertTrueValue(count($order->notes) === 1, 'paid_late must add exactly one order note.');
    assertSameValue(0, count($order->statusUpdates), 'paid_late must never move the order status.');
    assertTrueValue(!$order->paid, 'paid_late must never complete the order.');
    assertTrueValue($order->notes[0] !== '', 'the note must carry text.');
    assertSameValue('evt_late_1', $order->meta['_paymos_paid_late_note:inv_late_1'], 'the marker must record the event id after the note.');
    assertSameValue('expired', $order->meta['_paymos_last_status'], 'the recorded final status must stay untouched.');
}

function test_paid_late_duplicate_delivery_is_silent()
{
    $order = finalExpiredOrder();
    $mapper = new OrderMapper();

    $mapper->apply($order, paidLateEvent('evt_late_1'));
    $mapper->apply($order, paidLateEvent('evt_late_2'));

    assertTrueValue(count($order->notes) === 1, 'a sequential duplicate paid_late must not add a second note.');
    assertSameValue(0, count($order->statusUpdates), 'duplicates stay inert.');
    assertTrueValue(!$order->paid, 'duplicates never pay.');
}

function test_paid_late_after_terminal_keeps_the_terminal_status_record()
{
    $order = finalExpiredOrder();
    $mapper = new OrderMapper();

    $mapper->apply($order, paidLateEvent('evt_late_1', 'underpaid'));

    assertSameValue('expired', $order->meta['_paymos_last_status'], 'the late event must not overwrite the recorded status.');
    assertTrueValue(count($order->notes) === 1, 'the note still lands for an underpaid invoice.');
}
