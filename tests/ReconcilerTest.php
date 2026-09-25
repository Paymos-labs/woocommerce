<?php

declare(strict_types=1);

use Paymos\Client;
use Paymos\ClientConfig;
use Paymos\Http\HttpResponse;
use Paymos\Http\MockTransport;
use PaymosWooCommerce\Reconciler;

function test_reconciler_completes_unpaid_order_when_api_invoice_is_paid()
{
    if (!class_exists(Reconciler::class)) {
        throw new RuntimeException('Reconciler must exist so Woo orders can recover when webhook delivery is missed.');
    }

    $order = new FakeOrder('100.00', 'USD');
    $order->update_meta_data('_paymos_invoice_id', 'inv_123');
    $order->update_meta_data('_paymos_external_order_id', 'wc_100');
    $order->update_meta_data('_paymos_environment', 'sandbox');
    $order->update_meta_data('_paymos_project_id', 'prj_123');
    $order->update_meta_data('_paymos_invoice_amount', '100.00');
    $order->update_meta_data('_paymos_invoice_currency', 'USD');

    $transport = new MockTransport(array(
        new HttpResponse(200, json_encode(array(
            'invoice_id' => 'inv_123',
            'project_id' => 'prj_123',
            'status' => 'paid',
            'order' => array(
                'external_id' => 'wc_100',
                'amount' => '100.00',
                'currency' => 'USD',
            ),
        )), array()),
    ));
    $client = new Client(new ClientConfig('pk_test_key', 'sk_test_secret', 'https://api.paymos.test'), $transport, static function () {
        return 1709000000;
    });

    $count = Reconciler::reconcile_orders(array($order), static function ($environment = null) use ($client) {
        return $client;
    }, 1709000000);

    assertSameValue(1, $count, 'reconciler must count a recovered paid order.');
    assertSameValue(true, $order->paid, 'reconciler must complete unpaid Woo order when Paymos API status is paid.');
    assertSameValue('inv_123', $order->transactionId, 'reconciler must use invoice id as fallback transaction id.');
    assertSameValue('paid', $order->meta['_paymos_last_status'], 'reconciler must record API status on the order.');
}

/**
 * @param array<int, array<string, mixed>> $responses
 */
function paymos_reconcile_client(array $responses, &$transport = null)
{
    $queued = array();
    foreach ($responses as $response) {
        $queued[] = new HttpResponse(200, json_encode($response), array());
    }
    $transport = new MockTransport($queued);

    return new Client(new ClientConfig('pk_test_key', 'sk_test_secret', 'https://api.paymos.test'), $transport);
}

function paymos_reconcile_order($total = '100.00', $currency = 'USD', $lastStatus = '')
{
    $order = new FakeOrder($total, $currency);
    $order->update_meta_data('_paymos_invoice_id', 'inv_123');
    $order->update_meta_data('_paymos_external_order_id', 'wc_100');
    $order->update_meta_data('_paymos_environment', 'sandbox');
    $order->update_meta_data('_paymos_project_id', 'prj_123');
    $order->update_meta_data('_paymos_invoice_amount', $total);
    $order->update_meta_data('_paymos_invoice_currency', $currency);
    if ($lastStatus !== '') {
        $order->update_meta_data('_paymos_last_status', $lastStatus);
    }

    return $order;
}

function test_reconciler_compares_snapshot_amounts_numerically()
{
    // BUG-133: the snapshot is written with two decimals ("2500.00") while the
    // server echoes a JPY amount at the currency's own scale ("2500").
    $order = paymos_reconcile_order('2500.00', 'JPY');
    $client = paymos_reconcile_client(array(array(
        'invoice_id' => 'inv_123',
        'project_id' => 'prj_123',
        'status' => 'paid',
        'order' => array('external_id' => 'wc_100', 'amount' => '2500', 'currency' => 'JPY'),
    )));

    $count = Reconciler::reconcile_orders(array($order), static function () use ($client) {
        return $client;
    }, 1709000000);

    assertSameValue(1, $count, '"2500.00" and "2500" are the same amount and must reconcile.');
    assertSameValue(true, $order->paid, 'the missed payment must be applied.');
}

function test_reconciler_leaves_an_invoice_already_final_on_the_order_alone()
{
    // BUG-134: a cancelled order whose Paymos invoice is already recorded as
    // final has nothing left to learn — no API call, no fresh note every 10 min.
    $order = paymos_reconcile_order('100.00', 'USD', 'cancelled');
    $client = paymos_reconcile_client(array(), $transport);

    Reconciler::reconcile_orders(array($order), static function () use ($client) {
        return $client;
    }, 1709000000);

    assertSameValue(0, count($transport->requests()), 'an order whose invoice is already final must not be fetched again.');
    assertSameValue(0, count($order->notes), 'an order whose invoice is already final must not get another note.');
}

function test_reconciler_adds_no_note_when_the_invoice_status_did_not_change()
{
    $order = paymos_reconcile_order('100.00', 'USD', 'confirming');
    $client = paymos_reconcile_client(array(array(
        'invoice_id' => 'inv_123',
        'project_id' => 'prj_123',
        'status' => 'confirming',
        'order' => array('external_id' => 'wc_100', 'amount' => '100.00', 'currency' => 'USD'),
    )));

    Reconciler::reconcile_orders(array($order), static function () use ($client) {
        return $client;
    }, 1709000000);

    assertSameValue(0, count($order->notes), 'an unchanged status must not write a note.');
    assertSameValue(0, count($order->statusUpdates), 'an unchanged status must not touch the order status.');
}

function test_reconciler_does_not_report_a_rollback_for_an_invoice_that_was_never_paid()
{
    // A buyer who picked a token but has not paid leaves the invoice in
    // awaiting_payment with no payment.paid/remaining. That is not a reorg.
    $order = paymos_reconcile_order('100.00', 'USD', 'awaiting_client');
    $client = paymos_reconcile_client(array(array(
        'invoice_id' => 'inv_123',
        'project_id' => 'prj_123',
        'status' => 'awaiting_payment',
        'order' => array('external_id' => 'wc_100', 'amount' => '100.00', 'currency' => 'USD'),
        'payment' => array('currency' => 'USDT', 'network' => 'TRC20', 'expected' => '100'),
    )));

    Reconciler::reconcile_orders(array($order), static function () use ($client) {
        return $client;
    }, 1709000000);

    foreach ($order->notes as $note) {
        if (strpos($note, 'rolled back') !== false) {
            throw new RuntimeException('an unpaid invoice must not be reported as a rolled-back payment: ' . $note);
        }
    }
    assertSameValue('Awaiting Paymos payment.', $order->notes[count($order->notes) - 1], 'an unpaid invoice must get the neutral awaiting note.');
}

function test_reconciler_queries_waiting_orders_apart_from_closed_ones()
{
    // BUG-134: one "newest 50" across pending/on-hold/failed/cancelled let a
    // burst of cancellations push a stuck on-hold order out of the window.
    $GLOBALS['paymos_wc_get_orders_calls'] = array();
    Reconciler::run();

    $calls = $GLOBALS['paymos_wc_get_orders_calls'];
    assertSameValue(2, count($calls), 'waiting and closed orders must be fetched in separate windows.');
    assertSameValue(array('pending', 'on-hold'), $calls[0]['status'], 'the first window must be the orders still waiting for payment.');
    assertSameValue(array('failed', 'cancelled'), $calls[1]['status'], 'failed and cancelled orders must get their own window.');
}

if (!function_exists('wc_get_orders')) {
    function wc_get_orders(array $args)
    {
        $GLOBALS['paymos_wc_get_orders_calls'][] = $args;
        return array();
    }
}
