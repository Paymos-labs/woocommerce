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
    public function save() {}
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
    $order = paymos_renewal_order_with_invoice('expired');
    $order->update_meta_data('_paymos_environment', 'live');
    paymos_renewal_setup($order, array(
        array('invoice_id' => 'inv_fresh', 'status' => 'awaiting_client', 'payment_url' => 'https://checkout.paymos.test/inv_fresh', 'expires_at' => time() + 1800),
    ), $sent);

    $result = (new Gateway())->process_payment(100);

    assertSameValue(1, count($sent), 'an id from another environment is not asked about.');
    assertTrueValue($sent[0]['external_order_id'] !== 'wc_100_abc123', 'a new id is cut for the active environment.');
    assertSameValue('https://checkout.paymos.test/inv_fresh', $result['redirect'], 'the buyer lands on the invoice of the active environment.');
    assertSameValue('sandbox', $order->meta['_paymos_environment'], 'the order now points at the active environment.');
}
