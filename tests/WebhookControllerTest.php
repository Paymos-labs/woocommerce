<?php

declare(strict_types=1);

use Paymos\Client;
use Paymos\ClientConfig;
use Paymos\Http\HttpResponse;
use Paymos\Http\MockTransport;
use PaymosWooCommerce\WebhookController;

if (!class_exists('WP_REST_Response')) {
    final class WP_REST_Response
    {
        /** @var mixed */
        private $data;

        /** @var int */
        private $status;

        public function __construct($data, $status = 200)
        {
            $this->data = $data;
            $this->status = (int) $status;
        }

        public function get_status()
        {
            return $this->status;
        }

        public function get_data()
        {
            return $this->data;
        }
    }
}

if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request
    {
    }
}

final class FakeRestRequest extends WP_REST_Request
{
    /** @var string */
    private $body;

    /** @var array<string, string> */
    private $headers;

    /**
     * @param array<string, string> $headers
     */
    public function __construct($body, array $headers)
    {
        $this->body = (string) $body;
        $this->headers = $headers;
    }

    public function get_body()
    {
        return $this->body;
    }

    public function get_header($name)
    {
        $key = strtolower((string) $name);
        return array_key_exists($key, $this->headers) ? $this->headers[$key] : '';
    }
}

function wc_get_order($orderId)
{
    if (!isset($GLOBALS['paymos_test_wc_orders']) || count($GLOBALS['paymos_test_wc_orders']) === 0) {
        return false;
    }

    return $GLOBALS['paymos_test_wc_orders'][0];
}

function test_webhook_controller_rejects_paid_event_when_reverse_api_status_differs()
{
    paymos_store_credentials(array(
        'sandbox' => array(
            'api_key' => 'pk_test_key',
            'api_secret' => 'sk_test_secret',
            'project_id' => 'prj_123',
            'webhook_secret' => 'whsec_test',
            'base_url' => 'https://api.paymos.io',
        ),
    ));

    $order = new FakeOrder('100.00', 'USD');
    $order->update_meta_data('_paymos_external_order_id', 'wc_100');
    $order->update_meta_data('_paymos_environment', 'sandbox');
    $order->update_meta_data('_paymos_project_id', 'prj_123');
    $order->update_meta_data('_paymos_invoice_amount', '100.00');
    $order->update_meta_data('_paymos_invoice_currency', 'USD');
    $GLOBALS['paymos_test_wc_orders'] = array($order);

    $transport = new MockTransport(array(
        new HttpResponse(200, json_encode(array(
            'invoice_id' => 'inv_123',
            'project_id' => 'prj_123',
            'status' => 'expired',
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
    paymos_set_webhook_client_factory(static function () use ($client) {
        return $client;
    });

    $timestamp = time();
    $body = json_encode(array(
        'event_id' => 'evt_reverse_mismatch',
        'event_type' => 'invoice.paid',
        'version' => 1,
        'occurred_at' => $timestamp,
        'data' => array(
            'invoice_id' => 'inv_123',
            'project_id' => 'prj_123',
            'status' => 'paid',
            'is_test' => true,
            'order' => array(
                'external_id' => 'wc_100',
                'amount' => '100.00',
                'currency' => 'USD',
            ),
        ),
    ));

    $response = WebhookController::handle(new FakeRestRequest($body, array(
        'x-webhook-signature' => paymos_signed_header('whsec_test', $body, $timestamp),
    )));

    assertSameValue(400, $response->get_status(), 'reverse verification mismatch must reject terminal paid webhook.');
    assertSameValue(false, $order->paid, 'reverse verification mismatch must not complete Woo order.');

    paymos_set_webhook_client_factory(null);
    unset($GLOBALS['paymos_test_wc_orders']);
}

function test_webhook_controller_reverse_verifies_paid_event_before_completing_order()
{
    paymos_reset_test_state();
    paymos_store_credentials(array(
        'sandbox' => array(
            'api_key' => 'pk_test_key',
            'api_secret' => 'sk_test_secret',
            'project_id' => 'prj_123',
            'webhook_secret' => 'whsec_test',
            'base_url' => 'https://api.paymos.io',
        ),
    ));

    $order = new FakeOrder('100.00', 'USD');
    $order->update_meta_data('_paymos_external_order_id', 'wc_100');
    $order->update_meta_data('_paymos_environment', 'sandbox');
    $order->update_meta_data('_paymos_project_id', 'prj_123');
    $order->update_meta_data('_paymos_invoice_amount', '100.00');
    $order->update_meta_data('_paymos_invoice_currency', 'USD');
    $GLOBALS['paymos_test_wc_orders'] = array($order);

    $transport = new MockTransport(array(
        new HttpResponse(200, json_encode(array(
            'invoice_id' => 'inv_123',
            'project_id' => 'prj_123',
            'status' => 'paid',
            'order' => array('external_id' => 'wc_100', 'amount' => '100.00', 'currency' => 'USD'),
        )), array()),
    ));
    $client = new Client(new ClientConfig('pk_test_key', 'sk_test_secret', 'https://api.paymos.test'), $transport, static function () {
        return 1709000000;
    });
    paymos_set_webhook_client_factory(static function () use ($client) {
        return $client;
    });

    $timestamp = time();
    $body = json_encode(array(
        'event_id' => 'evt_reverse_ok',
        'event_type' => 'invoice.paid',
        'version' => 1,
        'occurred_at' => $timestamp,
        'data' => array(
            'invoice_id' => 'inv_123',
            'project_id' => 'prj_123',
            'status' => 'paid',
            'is_test' => true,
            'order' => array('external_id' => 'wc_100', 'amount' => '100.00', 'currency' => 'USD'),
        ),
    ));

    $response = WebhookController::handle(new FakeRestRequest($body, array(
        'x-webhook-signature' => paymos_signed_header('whsec_test', $body, $timestamp),
    )));

    assertSameValue(200, $response->get_status(), 'matching reverse verification must allow terminal paid webhook.');
    assertSameValue(true, $order->paid, 'matching reverse verification must complete Woo order.');
    assertSameValue(1, count($transport->requests()), 'terminal paid webhook must fetch Paymos invoice before completing order.');

    paymos_set_webhook_client_factory(null);
    unset($GLOBALS['paymos_test_wc_orders']);
}

function test_webhook_controller_does_not_commit_event_id_when_processing_fails()
{
    paymos_reset_test_state();
    paymos_store_credentials(array(
        'sandbox' => array(
            'api_key' => 'pk_test_key',
            'api_secret' => 'sk_test_secret',
            'project_id' => 'prj_123',
            'webhook_secret' => 'whsec_test',
            'base_url' => 'https://api.paymos.io',
        ),
    ));

    $order = new FakeOrder('100.00', 'USD');
    $order->update_meta_data('_paymos_external_order_id', 'wc_100');
    $order->update_meta_data('_paymos_environment', 'sandbox');
    $order->update_meta_data('_paymos_project_id', 'prj_123');
    $order->update_meta_data('_paymos_invoice_amount', '100.00');
    $order->update_meta_data('_paymos_invoice_currency', 'USD');
    $GLOBALS['paymos_test_wc_orders'] = array($order);

    $timestamp = time();
    $body = json_encode(array(
        'event_id' => 'evt_retry_after_failure',
        'event_type' => 'invoice.paid',
        'version' => 1,
        'occurred_at' => $timestamp,
        'data' => array(
            'invoice_id' => 'inv_123',
            'project_id' => 'prj_123',
            'status' => 'paid',
            'is_test' => true,
            'order' => array('external_id' => 'wc_100', 'amount' => '100.00', 'currency' => 'USD'),
        ),
    ));
    $signature = paymos_signed_header('whsec_test', $body, $timestamp);

    $badTransport = new MockTransport(array(
        new HttpResponse(200, json_encode(array(
            'invoice_id' => 'inv_123',
            'project_id' => 'prj_123',
            'status' => 'expired',
            'order' => array('external_id' => 'wc_100', 'amount' => '100.00', 'currency' => 'USD'),
        )), array()),
    ));
    paymos_set_webhook_client_factory(static function () use ($badTransport) {
        return new Client(new ClientConfig('pk_test_key', 'sk_test_secret', 'https://api.paymos.test'), $badTransport);
    });

    $first = WebhookController::handle(new FakeRestRequest($body, array('x-webhook-signature' => $signature)));
    assertSameValue(400, $first->get_status(), 'first webhook attempt must fail reverse verification.');

    $goodTransport = new MockTransport(array(
        new HttpResponse(200, json_encode(array(
            'invoice_id' => 'inv_123',
            'project_id' => 'prj_123',
            'status' => 'paid',
            'order' => array('external_id' => 'wc_100', 'amount' => '100.00', 'currency' => 'USD'),
        )), array()),
    ));
    paymos_set_webhook_client_factory(static function () use ($goodTransport) {
        return new Client(new ClientConfig('pk_test_key', 'sk_test_secret', 'https://api.paymos.test'), $goodTransport);
    });

    $second = WebhookController::handle(new FakeRestRequest($body, array('x-webhook-signature' => $signature)));

    assertSameValue(200, $second->get_status(), 'retry after failed processing must not be treated as duplicate.');
    assertSameValue(true, $order->paid, 'retry after failed processing must complete the order once API confirms paid.');

    paymos_set_webhook_client_factory(null);
    unset($GLOBALS['paymos_test_wc_orders']);
}
