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

function r31_woo_renewed_order()
{
    $old = paymos_renewal_order_with_invoice('expired');
    paymos_renewal_setup($old, array(
        array('invoice_id' => 'inv_old', 'status' => 'expired', 'payment_url' => 'https://checkout.paymos.io/inv_old'),
        array('invoice_id' => 'inv_fresh', 'status' => 'awaiting_client', 'payment_url' => 'https://checkout.paymos.io/inv_fresh'),
    ), $sent);
    (new PaymosWooCommerce\Gateway())->process_payment(100);
    $history = PaymosWooCommerce\CreationIntent::historicalBinding($old, 'inv_old');
    assertSameValue(array('invoice_id' => 'inv_old', 'external_order_id' => 'wc_100_abc123', 'project_id' => 'prj_123',
        'environment' => 'sandbox', 'amount' => '100.00', 'currency' => 'USD'), $history, 'Renewal must preserve the exact binding.');
    $order = new FakeOrder();
    $order->meta = $old->meta;
    $order->paid = true;
    $order->meta['_paymos_last_status'] = 'paid';
    $order->meta['_paymos_last_event_type'] = 'invoice.paid';
    $order->meta['_paymos_last_event_id'] = 'evt_current_paid';
    return $order;
}

function r31_woo_historical_event($id)
{
    return array('event_id' => $id, 'event_type' => 'invoice.paid_late', 'version' => 1, 'occurred_at' => time(),
        'data' => array('invoice_id' => 'inv_old', 'project_id' => 'prj_123', 'status' => 'expired', 'is_test' => true,
            'paid_late_at' => time(), 'order' => array('external_id' => 'wc_100_abc123', 'amount' => '100', 'currency' => 'USD')));
}

function r31_woo_deliver_historical(array $event, array $invoice)
{
    paymos_set_webhook_client_factory(static function () use ($invoice) {
        return new Paymos\Client(new Paymos\ClientConfig('pk_test_key', 'sk_test_secret', 'https://api.paymos.test'),
            new Paymos\Http\MockTransport(array(new Paymos\Http\HttpResponse(200, json_encode($invoice), array()))));
    });
    $body = json_encode($event);
    return PaymosWooCommerce\WebhookController::handle(new FakeRestRequest($body, array(
        'x-webhook-signature' => paymos_signed_header('whsec_test', $body, time()))));
}

function test_r31_woo_historical_paid_late_after_renewal_notes_once_without_touching_paid_generation()
{
    $order = r31_woo_renewed_order();
    $before = $order->meta;
    foreach (array('evt_late_old_1', 'evt_late_old_2') as $id) {
        $event = r31_woo_historical_event($id);
        assertSameValue(200, r31_woo_deliver_historical($event, $event['data'])->get_status(), 'Historical late payment must be accepted.');
    }
    assertSameValue(1, count($order->notes), 'One late invoice produces one note across event IDs.');
    assertSameValue('evt_late_old_1', $order->meta['_paymos_paid_late_note:inv_old'], 'Marker belongs to the historical invoice.');
    $after = $order->meta;
    unset($after['_paymos_paid_late_note:inv_old']);
    assertSameValue($before, $after, 'History must not overwrite current binding or last accepted event.');
    assertSameValue(0, $order->completionCalls, 'History never repeats native completion.');
    assertSameValue(array(), $order->statusUpdates, 'History never transitions native status.');
}

function test_r31_woo_historical_binding_rejects_unowned_or_unverified_events()
{
    foreach (array('ordinary', 'unknown', 'project', 'amount', 'currency', 'external', 'environment', 'api', 'gateway') as $case) {
        $order = r31_woo_renewed_order();
        $event = r31_woo_historical_event('evt_history_bad_' . $case);
        if ($case === 'ordinary') { $event['event_type'] = 'invoice.confirming'; $event['data']['status'] = 'confirming'; }
        if ($case === 'unknown') { $event['data']['invoice_id'] = 'inv_unknown'; }
        if ($case === 'project') { $event['data']['project_id'] = 'prj_other'; }
        if ($case === 'amount') { $event['data']['order']['amount'] = '999'; }
        if ($case === 'currency') { $event['data']['order']['currency'] = 'EUR'; }
        if ($case === 'external') { $event['data']['order']['external_id'] = 'wc_100_wrong'; }
        if ($case === 'environment') { $event['data']['is_test'] = false; }
        if ($case === 'gateway') { $order->method = 'other'; }
        $invoice = $event['data'];
        if ($case === 'api') { $invoice['invoice_id'] = 'inv_other'; }
        $before = $order->meta;
        assertSameValue(400, r31_woo_deliver_historical($event, $invoice)->get_status(), 'Unverified history must be rejected: ' . $case);
        assertSameValue($before, $order->meta, 'Rejected history must not mutate metadata.');
        assertSameValue(array(), $order->notes, 'Rejected history must not produce a note.');
    }
}

function test_r31_woo_current_pending_paid_late_recovers_exact_intent_before_note()
{
    list($order, $event) = pendingCreationFixture();
    unset($order->meta['_paymos_invoice_history']);
    $event['event_id'] = 'evt_current_pending_late';
    $event['event_type'] = 'invoice.paid_late';
    $event['data']['status'] = 'expired';
    $event['data']['paid_late_at'] = time();
    pendingCreationClient($event['data'], 2, $transport);

    assertSameValue(200, publicationDeliver($event)->get_status(), 'A current pending generation must recover before applying its late-payment note.');
    assertSameValue(array('POST', 'GET'), array_column($transport->requests(), 'method'), 'Recover independent ID, then reverse verify the late fact.');
    assertSameValue($order->get_meta('_paymos_creation_payload'), json_decode($transport->requests()[0]['body'], true), 'Recovery must use exact durable payload.');
    assertSameValue('inv_123', $order->get_meta('_paymos_invoice_id'), 'Only independent response supplies the binding.');
    assertSameValue('bound', $order->get_meta('_paymos_creation_intent'), 'Recovered generation is bound.');
    assertSameValue(1, count($order->notes), 'Late payment adds one note.');
    assertSameValue(0, $order->completionCalls, 'Late payment never completes native payment.');
    assertSameValue(array(), $order->statusUpdates, 'Late payment leaves native status intact.');
}

function test_r31_woo_current_pending_paid_late_rejects_other_recovered_invoice()
{
    list($order, $event) = pendingCreationFixture();
    unset($order->meta['_paymos_invoice_history']);
    $event['event_id'] = 'evt_current_pending_late_wrong';
    $event['event_type'] = 'invoice.paid_late';
    $event['data']['status'] = 'expired';
    $event['data']['paid_late_at'] = time();
    $invoice = $event['data'];
    $invoice['invoice_id'] = 'inv_independent_other';
    pendingCreationClient($invoice, 1, $transport);

    assertSameValue(400, publicationDeliver($event)->get_status(), 'Late event cannot choose the recovered invoice ID.');
    assertSameValue(array('POST'), array_column($transport->requests(), 'method'), 'Wrong ID is rejected before GET or note.');
    assertSameValue('inv_independent_other', $order->get_meta('_paymos_invoice_id'), 'Only independent POST binding is retained.');
    assertSameValue(array(), $order->notes, 'Rejected current late event adds no note.');
}
