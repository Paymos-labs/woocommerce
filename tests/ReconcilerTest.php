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
