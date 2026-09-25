<?php

declare(strict_types=1);

use PaymosWooCommerce\Gateway;
use PaymosWooCommerce\StorefrontHooks;

// BUG-090 (WooCommerce): a Woo order that failed (invoice.underpaid) or was put
// back to pending keeps its external_order_id; the server answers that id with
// the same, already final, invoice — and "Pay invoice" in My Account links to it.

final class RenewableFakeOrder
{
    /** @var array<string, mixed> */
    public $meta = array();

    /** @var array<int, array<int, string>> */
    public $statusUpdates = array();

    public function get_id() { return 100; }
    public function get_order_key() { return 'wc_order_abc123'; }
    public function get_total() { return '100.00'; }
    public function get_currency() { return 'USD'; }
    public function get_customer_id() { return 7; }
    public function get_payment_method() { return 'paymos'; }
    public function needs_payment() { return true; }
    public function update_meta_data($key, $value) { $this->meta[(string) $key] = $value; }
    public function get_meta($key, $single = true) { return array_key_exists((string) $key, $this->meta) ? $this->meta[(string) $key] : ''; }
    public function update_status($status, $note = '') { $this->statusUpdates[] = array($status, $note); }
    public function add_order_note($note) { $this->notes[] = (string) $note; }
    public function save() {}

    /** @var array<int, string> */
    public $notes = array();
}

if (!function_exists('WC')) {
    function WC()
    {
        return new class {
            /** @var object */
            public $cart;

            public function __construct()
            {
                $this->cart = new class {
                    public function empty_cart() {}
                };
            }
        };
    }
}

if (!function_exists('wc_add_notice')) {
    function wc_add_notice($message, $type = 'success')
    {
        $GLOBALS['paymos_test_notices'][] = array($type, (string) $message);
    }
}

if (!function_exists('esc_url')) {
    function esc_url($url)
    {
        return (string) $url;
    }
}

/**
 * @param array<int, array<string, mixed>> $invoices One create response per call, in order.
 * @param array<int, array<string, mixed>> $sent     Filled with each decoded request body.
 */
function paymos_renewal_setup(RenewableFakeOrder $order, array $invoices, &$sent)
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
    $GLOBALS['paymos_test_wc_orders'] = array($order);
    $sent = array();
    $GLOBALS['paymos_test_remote_handler'] = static function ($url, array $args) use (&$invoices, &$sent) {
        $sent[] = json_decode((string) $args['body'], true);
        return array(
            'response' => array('code' => 201),
            'body' => json_encode(array_shift($invoices)),
            'headers' => array('content-type' => 'application/json'),
        );
    };
}

function paymos_renewal_order_with_invoice($status, $expiresAt = 0)
{
    $order = new RenewableFakeOrder();
    $order->update_meta_data('_paymos_invoice_id', 'inv_old');
    $order->update_meta_data('_paymos_external_order_id', 'wc_100_abc123');
    $order->update_meta_data('_paymos_payment_url', 'https://checkout.paymos.test/inv_old');
    $order->update_meta_data('_paymos_invoice_amount', '100.00');
    $order->update_meta_data('_paymos_invoice_currency', 'USD');
    $order->update_meta_data('_paymos_last_status', $status);
    if ($expiresAt > 0) {
        $order->update_meta_data('_paymos_expires_at', (string) $expiresAt);
    }

    return $order;
}

function test_gateway_renews_the_invoice_of_a_failed_order()
{
    // The order meta says the invoice ended underpaid. The meta belongs to the
    // ORDER, not to one invoice, so the server is asked (the create call with
    // the stored id answers with the live invoice) before a new id is minted.
    $order = paymos_renewal_order_with_invoice('underpaid');
    paymos_renewal_setup($order, array(
        array('invoice_id' => 'inv_old', 'status' => 'underpaid', 'is_final' => true, 'payment_url' => 'https://checkout.paymos.test/inv_old'),
        array('invoice_id' => 'inv_fresh', 'status' => 'awaiting_client', 'payment_url' => 'https://checkout.paymos.test/inv_fresh', 'expires_at' => time() + 1800),
    ), $sent);

    $result = (new Gateway())->process_payment(100);

    assertSameValue('https://checkout.paymos.test/inv_fresh', $result['redirect'], 'a failed order must be sent to a fresh invoice.');
    assertSameValue(2, count($sent), 'the server confirms the old invoice ended, then a fresh one is cut.');
    assertSameValue('wc_100_abc123', $sent[0]['external_order_id'], 'the first call asks the server about the stored id.');
    assertTrueValue($sent[1]['external_order_id'] !== 'wc_100_abc123', 'the fresh invoice needs a new external order id — the old one answers with the final invoice.');
    assertSameValue('awaiting_client', $order->meta['_paymos_last_status'], 'the new invoice status replaces the old final one.');
}

function test_gateway_renews_when_the_server_returns_the_old_invoice_already_expired()
{
    // The expiry webhook never arrived: locally the invoice still looks open,
    // but the server answers the repeated id with an expired invoice.
    $order = paymos_renewal_order_with_invoice('awaiting_client');
    paymos_renewal_setup($order, array(
        array('invoice_id' => 'inv_old', 'status' => 'expired', 'is_final' => true, 'payment_url' => 'https://checkout.paymos.test/inv_old'),
        array('invoice_id' => 'inv_fresh', 'status' => 'awaiting_client', 'payment_url' => 'https://checkout.paymos.test/inv_fresh'),
    ), $sent);

    $result = (new Gateway())->process_payment(100);

    assertSameValue(2, count($sent), 'the reused id is tried first, then a fresh invoice is cut.');
    assertSameValue('wc_100_abc123', $sent[0]['external_order_id'], 'the first call reuses the stored id.');
    assertTrueValue($sent[1]['external_order_id'] !== 'wc_100_abc123', 'the second call must carry a new id.');
    assertSameValue('https://checkout.paymos.test/inv_fresh', $result['redirect'], 'the buyer must land on the fresh invoice.');
    assertSameValue('inv_fresh', $order->meta['_paymos_invoice_id'], 'the order must point at the fresh invoice.');
}

function test_gateway_keeps_an_open_invoice()
{
    $order = paymos_renewal_order_with_invoice('awaiting_client', time() + 600);
    paymos_renewal_setup($order, array(
        array('invoice_id' => 'inv_old', 'status' => 'awaiting_client', 'payment_url' => 'https://checkout.paymos.test/inv_old', 'expires_at' => time() + 600),
    ), $sent);

    $result = (new Gateway())->process_payment(100);

    assertSameValue(1, count($sent), 'an open invoice needs one idempotent call.');
    assertSameValue('wc_100_abc123', $sent[0]['external_order_id'], 'an open invoice keeps its id.');
    assertSameValue('https://checkout.paymos.test/inv_old', $result['redirect'], 'the buyer returns to the open invoice.');
}

function test_my_account_pay_action_does_not_link_to_a_dead_invoice()
{
    // An invoice past its deadline, or already final, must not be offered as
    // "Pay invoice": WooCommerce's own pay action runs checkout again, which
    // cuts a fresh invoice.
    $default = array('pay' => array('url' => 'https://shop.test/checkout/order-pay/100', 'name' => 'Pay'));

    $expired = StorefrontHooks::my_orders_actions($default, paymos_renewal_order_with_invoice('awaiting_client', time() - 3600));
    assertTrueValue(isset($expired['pay']) && !isset($expired['paymos_pay']), 'an expired invoice must leave WooCommerce\'s pay action in place.');

    $failed = StorefrontHooks::my_orders_actions($default, paymos_renewal_order_with_invoice('underpaid'));
    assertTrueValue(isset($failed['pay']) && !isset($failed['paymos_pay']), 'a failed invoice must leave WooCommerce\'s pay action in place.');

    $open = StorefrontHooks::my_orders_actions($default, paymos_renewal_order_with_invoice('awaiting_client', time() + 600));
    assertTrueValue(isset($open['paymos_pay']) && !isset($open['pay']), 'an open invoice is still offered directly.');
}

function test_gateway_asks_the_server_before_replacing_an_invoice_past_its_local_deadline()
{
    // BUG-163: the buyer picked a network, the server moved expires_at to
    // now + PaymentTtl and sent no webhook. The order meta still holds the
    // deadline from creation, long past. The invoice can still be paid, so it
    // must be kept: a second one next to it invites a second payment.
    $order = paymos_renewal_order_with_invoice('awaiting_client', time() - 3600);
    $extended = time() + 1500;
    paymos_renewal_setup($order, array(
        array('invoice_id' => 'inv_old', 'status' => 'awaiting_payment', 'is_final' => false, 'payment_url' => 'https://checkout.paymos.test/inv_old', 'expires_at' => $extended),
        array('invoice_id' => 'inv_second', 'status' => 'awaiting_client', 'payment_url' => 'https://checkout.paymos.test/inv_second'),
    ), $sent);

    $result = (new Gateway())->process_payment(100);

    assertSameValue(1, count($sent), 'one call: the server says the invoice is open, so nothing new is cut.');
    assertSameValue('wc_100_abc123', $sent[0]['external_order_id'], 'a past local deadline must not mint a new id before the server is asked.');
    assertSameValue('https://checkout.paymos.test/inv_old', $result['redirect'], 'the buyer returns to the invoice they already started.');
    assertSameValue('inv_old', $order->meta['_paymos_invoice_id'], 'the order keeps its invoice.');
    assertSameValue('wc_100_abc123', $order->meta['_paymos_external_order_id'], 'the order keeps its external order id.');
    assertSameValue('awaiting_payment', $order->meta['_paymos_last_status'], 'the status is refreshed from the server.');
    assertSameValue((string) $extended, $order->meta['_paymos_expires_at'], 'the deadline is refreshed from the server.');
}

function test_gateway_keeps_an_invoice_the_server_holds_in_confirming_or_part_paid()
{
    // Funds in flight or a part payment in: the server closes this invoice, the
    // plugin never replaces it, however far back the deadline lies.
    foreach (array('confirming', 'underpaid_waiting') as $status) {
        $order = paymos_renewal_order_with_invoice('awaiting_client', time() - 3600);
        paymos_renewal_setup($order, array(
            array('invoice_id' => 'inv_old', 'status' => $status, 'is_final' => false, 'payment_url' => 'https://checkout.paymos.test/inv_old', 'expires_at' => time() - 600),
            array('invoice_id' => 'inv_second', 'status' => 'awaiting_client', 'payment_url' => 'https://checkout.paymos.test/inv_second'),
        ), $sent);

        $result = (new Gateway())->process_payment(100);

        assertSameValue(1, count($sent), $status . ': the open invoice needs one idempotent call and no second invoice.');
        assertSameValue('wc_100_abc123', $sent[0]['external_order_id'], $status . ': the stored id is reused.');
        assertSameValue('https://checkout.paymos.test/inv_old', $result['redirect'], $status . ': the buyer returns to the same invoice.');
    }
}

function test_gateway_renews_past_the_local_deadline_only_once_the_server_says_expired()
{
    $order = paymos_renewal_order_with_invoice('awaiting_client', time() - 3600);
    paymos_renewal_setup($order, array(
        array('invoice_id' => 'inv_old', 'status' => 'expired', 'is_final' => true, 'payment_url' => 'https://checkout.paymos.test/inv_old', 'expires_at' => time() - 3600),
        array('invoice_id' => 'inv_fresh', 'status' => 'awaiting_client', 'payment_url' => 'https://checkout.paymos.test/inv_fresh', 'expires_at' => time() + 1800),
    ), $sent);

    $result = (new Gateway())->process_payment(100);

    assertSameValue(2, count($sent), 'the server is asked first, then a fresh invoice is cut.');
    assertSameValue('wc_100_abc123', $sent[0]['external_order_id'], 'the first call carries the stored id.');
    assertTrueValue($sent[1]['external_order_id'] !== 'wc_100_abc123', 'the replacement carries a new id.');
    assertSameValue('https://checkout.paymos.test/inv_fresh', $result['redirect'], 'the buyer lands on the fresh invoice.');
}

function test_gateway_cuts_a_fresh_id_when_the_stored_one_belongs_to_another_environment()
{
    // The key is unique per project, not per environment: the server answers a
    // live id asked from sandbox with 409 invoice_idempotency_conflict
    // (CreateInvoiceShared.TryAssembleExistingAsync). The stored id is only
    // reused within the environment and project it was cut in.
    // BUG-166: before the fresh id, the live invoice is closed with live
    // credentials — here the server says it expired.
    $order = paymos_renewal_order_with_invoice('expired');
    $order->update_meta_data('_paymos_environment', 'live');
    paymos_replacement_setup($order, array(
        'cancel' => array(paymos_replacement_problem(409, 'invoice_cannot_be_cancelled')),
        'get' => array(array(200, array('invoice_id' => 'inv_old', 'status' => 'expired', 'is_final' => true))),
        'create' => array(array(201, array('invoice_id' => 'inv_fresh', 'status' => 'awaiting_client', 'payment_url' => 'https://checkout.paymos.test/inv_fresh', 'expires_at' => time() + 1800))),
    ), $calls, paymos_replacement_both_environments());

    $result = (new Gateway())->process_payment(100);

    assertSameValue(array('POST /v1/invoices/inv_old/cancel pk_live', 'GET /v1/invoices/inv_old pk_live', 'POST /v1/invoices pk_test'), $calls, 'the live invoice is closed in live; only the create goes to the active environment.');
    assertTrueValue($order->meta['_paymos_external_order_id'] !== 'wc_100_abc123', 'a new id is cut for the active environment.');
    assertSameValue('https://checkout.paymos.test/inv_fresh', $result['redirect'], 'the buyer lands on the invoice of the active environment.');
    assertSameValue('sandbox', $order->meta['_paymos_environment'], 'the order now points at the active environment.');
}

function test_gateway_does_not_replace_an_invoice_whose_environment_has_no_credentials()
{
    // The stored invoice is a live one and live is no longer connected: it
    // cannot be cancelled or read, so it is not proven closed.
    $order = paymos_renewal_order_with_invoice('expired');
    $order->update_meta_data('_paymos_environment', 'live');
    paymos_replacement_setup($order, array(), $calls);

    $result = (new Gateway())->process_payment(100);

    assertSameValue('failure', $result['result'], 'the checkout fails.');
    assertSameValue(array(), $calls, 'nothing is sent: live has no credentials and sandbox must not cut a second invoice.');
    assertSameValue('inv_old', $order->meta['_paymos_invoice_id'], 'the order keeps its old invoice.');
    assertSameValue(1, count($order->notes), 'the order gets a note for review.');
}

function paymos_replacement_both_environments()
{
    return array(
        'sandbox' => array('api_key' => 'pk_test_key', 'api_secret' => 'sk_test_secret', 'project_id' => 'prj_123', 'webhook_secret' => 'whsec_test', 'base_url' => 'https://api.paymos.io'),
        'live' => array('api_key' => 'pk_live_key', 'api_secret' => 'sk_live_secret', 'project_id' => 'prj_live', 'webhook_secret' => 'whsec_live', 'base_url' => 'https://api.paymos.io'),
    );
}

/**
 * Routes each signed request by its shape: POST …/cancel, GET …/{id}, POST
 * /v1/invoices. Each queue holds array(httpCode, body) answers in order.
 *
 * @param array<string, array<int, array<int, mixed>>> $answers keys: create, cancel, get
 * @param array<int, string>                           $calls   "METHOD path key-prefix" per request
 */
function paymos_replacement_setup(RenewableFakeOrder $order, array $answers, &$calls, array $environments = array())
{
    paymos_reset_test_state();
    paymos_store_credentials($environments ?: array(
        'sandbox' => array(
            'api_key' => 'pk_test_key',
            'api_secret' => 'sk_test_secret',
            'project_id' => 'prj_123',
            'webhook_secret' => 'whsec_test',
            'base_url' => 'https://api.paymos.io',
        ),
    ));
    $GLOBALS['paymos_test_wc_orders'] = array($order);
    $GLOBALS['paymos_test_notices'] = array();
    $calls = array();
    $GLOBALS['paymos_test_remote_handler'] = static function ($url, array $args) use (&$answers, &$calls) {
        $path = (string) parse_url((string) $url, PHP_URL_PATH);
        $method = strtoupper((string) $args['method']);
        $authorization = isset($args['headers']['Authorization']) ? (string) $args['headers']['Authorization'] : '';
        $key = preg_match('/^HMAC-SHA256 (pk_[a-z]+)_/', $authorization, $m) ? $m[1] : '?';
        $calls[] = $method . ' ' . $path . ' ' . $key;
        $queue = $method === 'GET' ? 'get' : (substr($path, -7) === '/cancel' ? 'cancel' : 'create');
        $answer = isset($answers[$queue]) ? array_shift($answers[$queue]) : null;
        if ($answer === null) {
            return new WP_Error('no_answer', 'No queued answer for ' . $method . ' ' . $path);
        }

        return array(
            'response' => array('code' => (int) $answer[0]),
            'body' => json_encode($answer[1]),
            'headers' => array('content-type' => (int) $answer[0] >= 400 ? 'application/problem+json' : 'application/json'),
        );
    };
}

function paymos_replacement_problem($status, $code)
{
    return array($status, array(
        'type' => 'https://paymos.io/errors/' . $code,
        'title' => 'Error',
        'status' => $status,
        'detail' => 'Problem ' . $code . '.',
        'code' => $code,
    ));
}

function paymos_replacement_changed_order()
{
    // Cut for 50.00; the order now totals 100.00.
    $order = paymos_renewal_order_with_invoice('awaiting_client', time() + 600);
    $order->update_meta_data('_paymos_invoice_amount', '50.00');
    $order->update_meta_data('_paymos_environment', 'sandbox');
    $order->update_meta_data('_paymos_project_id', 'prj_123');

    return $order;
}

function test_gateway_cancels_the_old_invoice_before_replacing_it_for_a_changed_amount()
{
    // BUG-166: a changed order gets a new external_order_id; the old invoice
    // is cancelled on the server first, or the buyer could pay both.
    $order = paymos_replacement_changed_order();
    paymos_replacement_setup($order, array(
        'cancel' => array(array(200, array('invoice_id' => 'inv_old', 'status' => 'cancelled', 'is_final' => true))),
        'create' => array(array(201, array('invoice_id' => 'inv_fresh', 'status' => 'awaiting_client', 'payment_url' => 'https://checkout.paymos.test/inv_fresh'))),
    ), $calls);

    $result = (new Gateway())->process_payment(100);

    assertSameValue(array('POST /v1/invoices/inv_old/cancel pk_test', 'POST /v1/invoices pk_test'), $calls, 'the old invoice is cancelled before the new one is created.');
    assertSameValue('https://checkout.paymos.test/inv_fresh', $result['redirect'], 'the buyer lands on the new invoice.');
    assertSameValue('inv_fresh', $order->meta['_paymos_invoice_id'], 'the order points at the new invoice.');
}

function test_gateway_does_not_replace_an_invoice_the_buyer_can_still_pay()
{
    // Only awaiting_client is cancellable on the server. A network picked,
    // funds confirming, a part paid or a full payment keeps the old invoice.
    foreach (array('awaiting_payment', 'confirming', 'underpaid_waiting', 'paid') as $status) {
        $order = paymos_replacement_changed_order();
        paymos_replacement_setup($order, array(
            'cancel' => array(paymos_replacement_problem(409, 'invoice_cannot_be_cancelled')),
            'get' => array(array(200, array('invoice_id' => 'inv_old', 'status' => $status))),
        ), $calls);

        $result = (new Gateway())->process_payment(100);

        assertSameValue('failure', $result['result'], $status . ': the checkout fails.');
        assertSameValue(array('POST /v1/invoices/inv_old/cancel pk_test', 'GET /v1/invoices/inv_old pk_test'), $calls, $status . ': cancel, read, and no create.');
        assertSameValue('inv_old', $order->meta['_paymos_invoice_id'], $status . ': the order keeps its old invoice.');
        assertSameValue('wc_100_abc123', $order->meta['_paymos_external_order_id'], $status . ': the external order id is not replaced.');
        assertSameValue(array(array('on-hold', '')), $order->statusUpdates, $status . ': the order is put on hold for review.');
        assertSameValue(1, count($order->notes), $status . ': the order gets a note.');
        assertTrueValue(strpos($order->notes[0], 'inv_old') !== false, $status . ': the note names the old invoice.');
        assertSameValue('error', $GLOBALS['paymos_test_notices'][0][0], $status . ': the buyer sees an error notice.');
    }
}

function test_gateway_does_not_replace_an_invoice_the_server_answers_404_for()
{
    $order = paymos_replacement_changed_order();
    paymos_replacement_setup($order, array(
        'cancel' => array(paymos_replacement_problem(404, 'not_found')),
    ), $calls);

    $result = (new Gateway())->process_payment(100);

    assertSameValue('failure', $result['result'], 'the checkout fails.');
    assertSameValue(array('POST /v1/invoices/inv_old/cancel pk_test'), $calls, 'no invoice is created after a 404.');
    assertSameValue('inv_old', $order->meta['_paymos_invoice_id'], 'the order keeps its old invoice.');
}

function test_gateway_cancels_an_unstarted_invoice_past_its_deadline_before_replacing_it()
{
    // The server answers the stored id with an invoice still awaiting_client
    // but past expires_at (its expiry job has not run): cancel, then replace.
    $order = paymos_renewal_order_with_invoice('awaiting_client', time() - 3600);
    paymos_replacement_setup($order, array(
        'create' => array(
            array(200, array('invoice_id' => 'inv_old', 'status' => 'awaiting_client', 'is_final' => false, 'payment_url' => 'https://checkout.paymos.test/inv_old', 'expires_at' => time() - 3600)),
            array(201, array('invoice_id' => 'inv_fresh', 'status' => 'awaiting_client', 'payment_url' => 'https://checkout.paymos.test/inv_fresh')),
        ),
        'cancel' => array(array(200, array('invoice_id' => 'inv_old', 'status' => 'cancelled', 'is_final' => true))),
    ), $calls);

    $result = (new Gateway())->process_payment(100);

    assertSameValue(array('POST /v1/invoices pk_test', 'POST /v1/invoices/inv_old/cancel pk_test', 'POST /v1/invoices pk_test'), $calls, 'read (idempotent create), cancel, create.');
    assertSameValue('https://checkout.paymos.test/inv_fresh', $result['redirect'], 'the buyer lands on the new invoice.');
}

function test_gateway_cancels_the_old_invoice_in_its_own_environment()
{
    // The merchant switched Sandbox → Live: only sandbox credentials can
    // cancel the sandbox invoice.
    $order = paymos_renewal_order_with_invoice('awaiting_client', time() + 600);
    $order->update_meta_data('_paymos_environment', 'sandbox');
    $order->update_meta_data('_paymos_project_id', 'prj_123');
    paymos_replacement_setup($order, array(
        'cancel' => array(array(200, array('invoice_id' => 'inv_old', 'status' => 'cancelled', 'is_final' => true))),
        'create' => array(array(201, array('invoice_id' => 'inv_live', 'status' => 'awaiting_client', 'payment_url' => 'https://checkout.paymos.test/inv_live'))),
    ), $calls, paymos_replacement_both_environments());
    update_option('woocommerce_paymos_settings', array('mode' => 'live', 'enabled' => 'yes'));
    PaymosWooCommerce\Config::reset_cache();

    $result = (new Gateway())->process_payment(100);

    assertSameValue(array('POST /v1/invoices/inv_old/cancel pk_test', 'POST /v1/invoices pk_live'), $calls, 'cancel with sandbox credentials, create with live.');
    assertSameValue('https://checkout.paymos.test/inv_live', $result['redirect'], 'the buyer lands on the live invoice.');
}
