<?php

declare(strict_types=1);

use PaymosWooCommerce\Gateway;
use PaymosWooCommerce\StorefrontHooks;

// BUG-090 (WooCommerce): a Woo order that failed (invoice.underpaid) or was put
// back to pending keeps its external_order_id; the server answers that id with
// the same, already final, invoice — and "Pay invoice" in My Account links to it.

class RenewableFakeOrder
{
    /** @var array<string, mixed> */
    public $meta = array();

    /** @var array<int, array<int, string>> */
    public $statusUpdates = array();

    public function get_data_store() { return new NativeOrderStoreFixture(); }
    public function read_meta_data($force = false) {}
    public function get_id() { return 100; }
    public function get_order_key() { return 'wc_order_abc123'; }
    public function get_total() { return '100.00'; }
    public function get_currency() { return 'USD'; }
    public function get_customer_id() { return 7; }
    public function get_payment_method() { return 'paymos'; }
    public function needs_payment() { return true; }
    public function update_meta_data($key, $value) { $this->meta[(string) $key] = $value; }
    public function get_meta($key, $single = true) { return array_key_exists((string) $key, $this->meta) ? $this->meta[(string) $key] : ''; }
    public function update_status($status, $note = '') { $this->statusUpdates[] = array($status, $note); return true; }
    public function add_order_note($note) { $this->notes[] = (string) $note; }
    public function save() { return 100; }
    public function is_paid() { return false; }
    public function get_status() { return $this->statusUpdates ? $this->statusUpdates[count($this->statusUpdates)-1][0] : 'pending'; }

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

if (!function_exists('wp_get_attachment_image_url')) {
    function wp_get_attachment_image_url($attachment_id, $size = 'thumbnail', $icon = false)
    {
        $map = isset($GLOBALS['paymos_test_attachment_urls']) && is_array($GLOBALS['paymos_test_attachment_urls'])
            ? $GLOBALS['paymos_test_attachment_urls']
            : array();
        $id = (int) $attachment_id;
        return array_key_exists($id, $map) ? $map[$id] : false;
    }
}

if (!class_exists('WC_Order_Item_Product')) {
    class WC_Order_Item_Product
    {
        /** @var string */
        private $name;
        /** @var int|float|string */
        private $quantity;
        /** @var object|false|null */
        private $product;
        /** @var string */
        private $type;

        /**
         * @param int|float|string $quantity
         * @param object|false|null $product
         */
        public function __construct($name, $quantity, $product = null, $type = 'line_item')
        {
            $this->name = (string) $name;
            $this->quantity = $quantity;
            $this->product = $product;
            $this->type = (string) $type;
        }

        public function get_type() { return $this->type; }
        public function get_name() { return $this->name; }
        public function get_quantity() { return $this->quantity; }
        public function get_product() { return $this->product; }
    }
}

final class PresentationFakeProduct
{
    private $shortDescription;
    private $description;
    private $imageId;

    public function __construct($shortDescription = '', $description = '', $imageId = 0)
    {
        $this->shortDescription = (string) $shortDescription;
        $this->description = (string) $description;
        $this->imageId = (int) $imageId;
    }

    public function get_short_description() { return $this->shortDescription; }
    public function get_description() { return $this->description; }
    public function get_image_id() { return $this->imageId; }
}

final class PresentationFakeOrder extends RenewableFakeOrder
{
    /** @var string */
    public $total = '47.00';
    /** @var bool */
    public $pricesIncludeTax = true;
    /** @var array<int, object> */
    public $items = array();
    /** @var array<int, string> */
    public $subtotals = array();
    /** @var array<int, array<int, mixed>> */
    public $subtotalCalls = array();

    public function get_total() { return $this->total; }
    public function get_prices_include_tax() { return $this->pricesIncludeTax; }

    public function get_items($types = 'line_item')
    {
        if ($types === '' || $types === null) {
            return $this->items;
        }
        $wanted = is_array($types) ? $types : array((string) $types);
        return array_values(array_filter($this->items, static function ($item) use ($wanted) {
            return method_exists($item, 'get_type') && in_array((string) $item->get_type(), $wanted, true);
        }));
    }

    public function get_item_subtotal($item, $inc_tax = false, $round = true)
    {
        $this->subtotalCalls[] = array($item, $inc_tax, $round);
        $idx = array_search($item, $this->items, true);
        return $idx !== false && array_key_exists($idx, $this->subtotals) ? $this->subtotals[$idx] : '0.00';
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
    $order->update_meta_data('_paymos_environment', 'sandbox');
    $order->update_meta_data('_paymos_project_id', 'prj_123');
    $order->update_meta_data('_paymos_invoice_id', 'inv_old');
    $order->update_meta_data('_paymos_external_order_id', 'wc_100_abc123');
    $order->update_meta_data('_paymos_payment_url', 'https://checkout.paymos.io/inv_old');
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
        array('invoice_id' => 'inv_old', 'status' => 'underpaid', 'is_final' => true, 'payment_url' => 'https://checkout.paymos.io/inv_old'),
        array('invoice_id' => 'inv_fresh', 'status' => 'awaiting_client', 'payment_url' => 'https://checkout.paymos.io/inv_fresh', 'expires_at' => time() + 1800),
    ), $sent);

    $result = (new Gateway())->process_payment(100);

    assertSameValue('https://checkout.paymos.io/inv_fresh', $result['redirect'], 'a failed order must be sent to a fresh invoice.');
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
        array('invoice_id' => 'inv_old', 'status' => 'expired', 'is_final' => true, 'payment_url' => 'https://checkout.paymos.io/inv_old'),
        array('invoice_id' => 'inv_fresh', 'status' => 'awaiting_client', 'payment_url' => 'https://checkout.paymos.io/inv_fresh'),
    ), $sent);

    $result = (new Gateway())->process_payment(100);

    assertSameValue(2, count($sent), 'the reused id is tried first, then a fresh invoice is cut.');
    assertSameValue('wc_100_abc123', $sent[0]['external_order_id'], 'the first call reuses the stored id.');
    assertTrueValue($sent[1]['external_order_id'] !== 'wc_100_abc123', 'the second call must carry a new id.');
    assertSameValue('https://checkout.paymos.io/inv_fresh', $result['redirect'], 'the buyer must land on the fresh invoice.');
    assertSameValue('inv_fresh', $order->meta['_paymos_invoice_id'], 'the order must point at the fresh invoice.');
}

function test_gateway_keeps_an_open_invoice()
{
    $order = paymos_renewal_order_with_invoice('awaiting_client', time() + 600);
    paymos_renewal_setup($order, array(
        array('invoice_id' => 'inv_old', 'status' => 'awaiting_client', 'payment_url' => 'https://checkout.paymos.io/inv_old', 'expires_at' => time() + 600),
    ), $sent);

    $result = (new Gateway())->process_payment(100);

    assertSameValue(1, count($sent), 'an open invoice needs one idempotent call.');
    assertSameValue('wc_100_abc123', $sent[0]['external_order_id'], 'an open invoice keeps its id.');
    assertSameValue('https://checkout.paymos.io/inv_old', $result['redirect'], 'the buyer returns to the open invoice.');
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
        array('invoice_id' => 'inv_old', 'status' => 'awaiting_payment', 'is_final' => false, 'payment_url' => 'https://checkout.paymos.io/inv_old', 'expires_at' => $extended),
        array('invoice_id' => 'inv_second', 'status' => 'awaiting_client', 'payment_url' => 'https://checkout.paymos.io/inv_second'),
    ), $sent);

    $result = (new Gateway())->process_payment(100);

    assertSameValue(1, count($sent), 'one call: the server says the invoice is open, so nothing new is cut.');
    assertSameValue('wc_100_abc123', $sent[0]['external_order_id'], 'a past local deadline must not mint a new id before the server is asked.');
    assertSameValue('https://checkout.paymos.io/inv_old', $result['redirect'], 'the buyer returns to the invoice they already started.');
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
            array('invoice_id' => 'inv_old', 'status' => $status, 'is_final' => false, 'payment_url' => 'https://checkout.paymos.io/inv_old', 'expires_at' => time() - 600),
            array('invoice_id' => 'inv_second', 'status' => 'awaiting_client', 'payment_url' => 'https://checkout.paymos.io/inv_second'),
        ), $sent);

        $result = (new Gateway())->process_payment(100);

        assertSameValue(1, count($sent), $status . ': the open invoice needs one idempotent call and no second invoice.');
        assertSameValue('wc_100_abc123', $sent[0]['external_order_id'], $status . ': the stored id is reused.');
        assertSameValue('https://checkout.paymos.io/inv_old', $result['redirect'], $status . ': the buyer returns to the same invoice.');
    }
}

function test_gateway_renews_past_the_local_deadline_only_once_the_server_says_expired()
{
    $order = paymos_renewal_order_with_invoice('awaiting_client', time() - 3600);
    paymos_renewal_setup($order, array(
        array('invoice_id' => 'inv_old', 'status' => 'expired', 'is_final' => true, 'payment_url' => 'https://checkout.paymos.io/inv_old', 'expires_at' => time() - 3600),
        array('invoice_id' => 'inv_fresh', 'status' => 'awaiting_client', 'payment_url' => 'https://checkout.paymos.io/inv_fresh', 'expires_at' => time() + 1800),
    ), $sent);

    $result = (new Gateway())->process_payment(100);

    assertSameValue(2, count($sent), 'the server is asked first, then a fresh invoice is cut.');
    assertSameValue('wc_100_abc123', $sent[0]['external_order_id'], 'the first call carries the stored id.');
    assertTrueValue($sent[1]['external_order_id'] !== 'wc_100_abc123', 'the replacement carries a new id.');
    assertSameValue('https://checkout.paymos.io/inv_fresh', $result['redirect'], 'the buyer lands on the fresh invoice.');
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
        'create' => array(array(201, array('invoice_id' => 'inv_fresh', 'status' => 'awaiting_client', 'payment_url' => 'https://checkout.paymos.io/inv_fresh', 'expires_at' => time() + 1800))),
    ), $calls, paymos_replacement_both_environments());

    $result = (new Gateway())->process_payment(100);

    assertSameValue(array('POST /v1/invoices/inv_old/cancel pk_live', 'GET /v1/invoices/inv_old pk_live', 'POST /v1/invoices pk_test'), $calls, 'the live invoice is closed in live; only the create goes to the active environment.');
    assertTrueValue($order->meta['_paymos_external_order_id'] !== 'wc_100_abc123', 'a new id is cut for the active environment.');
    assertSameValue('https://checkout.paymos.io/inv_fresh', $result['redirect'], 'the buyer lands on the invoice of the active environment.');
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
        'create' => array(array(201, array('invoice_id' => 'inv_fresh', 'status' => 'awaiting_client', 'payment_url' => 'https://checkout.paymos.io/inv_fresh'))),
    ), $calls);

    $result = (new Gateway())->process_payment(100);

    assertSameValue(array('POST /v1/invoices/inv_old/cancel pk_test', 'POST /v1/invoices pk_test'), $calls, 'the old invoice is cancelled before the new one is created.');
    assertSameValue('https://checkout.paymos.io/inv_fresh', $result['redirect'], 'the buyer lands on the new invoice.');
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
            array(200, array('invoice_id' => 'inv_old', 'status' => 'awaiting_client', 'is_final' => false, 'payment_url' => 'https://checkout.paymos.io/inv_old', 'expires_at' => time() - 3600)),
            array(201, array('invoice_id' => 'inv_fresh', 'status' => 'awaiting_client', 'payment_url' => 'https://checkout.paymos.io/inv_fresh')),
        ),
        'cancel' => array(array(200, array('invoice_id' => 'inv_old', 'status' => 'cancelled', 'is_final' => true))),
    ), $calls);

    $result = (new Gateway())->process_payment(100);

    assertSameValue(array('POST /v1/invoices pk_test', 'POST /v1/invoices/inv_old/cancel pk_test', 'POST /v1/invoices pk_test'), $calls, 'read (idempotent create), cancel, create.');
    assertSameValue('https://checkout.paymos.io/inv_fresh', $result['redirect'], 'the buyer lands on the new invoice.');
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
        'create' => array(array(201, array('invoice_id' => 'inv_live', 'status' => 'awaiting_client', 'payment_url' => 'https://checkout.paymos.io/inv_live'))),
    ), $calls, paymos_replacement_both_environments());
    update_option('woocommerce_paymos_settings', array('mode' => 'live', 'enabled' => 'yes'));
    PaymosWooCommerce\Config::reset_cache();

    $result = (new Gateway())->process_payment(100);

    assertSameValue(array('POST /v1/invoices/inv_old/cancel pk_test', 'POST /v1/invoices pk_live'), $calls, 'cancel with sandbox credentials, create with live.');
    assertSameValue('https://checkout.paymos.io/inv_live', $result['redirect'], 'the buyer lands on the live invoice.');
}

function test_gateway_blocked_replacement_names_the_real_reason_and_asks_the_buyer_to_contact_the_store()
{
    // BUG-181: the note blamed the amount ("Paymos payment amount needs manual
    // review.") and the buyer read "unable to create invoice". A replacement is
    // also blocked after a mode or project change, and the old invoice may
    // still be paid, so the buyer must not be sent to pay again.
    $order = paymos_replacement_changed_order();
    paymos_replacement_setup($order, array(
        'cancel' => array(paymos_replacement_problem(409, 'invoice_cannot_be_cancelled')),
        'get' => array(array(200, array('invoice_id' => 'inv_old', 'status' => 'awaiting_payment'))),
    ), $calls);

    (new Gateway())->process_payment(100);

    $result = \Paymos\Plugin\InvoiceReplacementResult::blocked('inv_old', 'awaiting_payment', \Paymos\Plugin\InvoiceReplacementResult::REASON_OPEN);
    assertSameValue(array('Paymos payment needs manual review. ' . $result->summary()), $order->notes, 'the note carries the SDK summary, not an amount mismatch.');
    assertSameValue(
        array(array('error', (new \Paymos\Plugin\InvoiceReplacementBlockedException($result))->getMessage())),
        $GLOBALS['paymos_test_notices'],
        'the buyer reads the SDK buyer message.'
    );
}

function test_gateway_catalogues_carry_the_blocked_replacement_strings()
{
    $expected = array(
        'Paymos payment needs manual review.',
        'The store needs to review this order before payment can continue. Please contact the store.',
    );
    $dir = dirname(__DIR__) . '/languages/';
    foreach (array_merge(array('paymos-for-woocommerce.pot'), array_map(static function ($locale) {
        return 'paymos-for-woocommerce-' . $locale . '.po';
    }, array('ru_RU', 'de_DE', 'es_ES', 'tr_TR', 'zh_CN'))) as $file) {
        $text = (string) file_get_contents($dir . $file);
        foreach ($expected as $msgid) {
            assertTrueValue(strpos($text, 'msgid "' . $msgid . '"') !== false, $file . ': has "' . $msgid . '".');
        }
    }
}

function test_gateway_sends_single_product_using_get_item_subtotal_and_handles_deleted_or_minimal_products()
{
    $order = new PresentationFakeOrder();
    $order->total = '47.00';
    $order->pricesIncludeTax = true;
    $item = new WC_Order_Item_Product(
        '<b>Canvas</b> &middot; Blue',
        2,
        new PresentationFakeProduct('<p>Archival&nbsp;print</p>', '', 55)
    );
    $order->items = array($item);
    $order->subtotals = array(24.5);

    paymos_renewal_setup($order, array(
        array('invoice_id' => 'inv_single', 'status' => 'awaiting_client', 'payment_url' => 'https://checkout.paymos.io/inv_single'),
    ), $sent);
    $GLOBALS['paymos_test_attachment_urls'] = array(55 => 'https://shop.example/images/canvas.webp');

    $result = (new Gateway())->process_payment(100);

    assertSameValue('success', $result['result'], 'Single product checkout must succeed.');
    assertSameValue('47', $sent[0]['amount'], 'Native grand total must stay separate from item unit_amount * qty.');
    assertSameValue(array(
        array(
            'title' => 'Canvas · Blue',
            'quantity' => 2,
            'unit_amount' => '24.50',
            'description' => 'Archival print',
            'image_url' => 'https://shop.example/images/canvas.webp',
        ),
    ), $sent[0]['products'], 'Single item must normalize HTML, quantity, and unit_amount.');
    assertSameValue(1, count($order->subtotalCalls), 'WooCommerce must invoke get_item_subtotal once for the product item.');
    assertSameValue(array($item, true, true), $order->subtotalCalls[0], 'get_item_subtotal must receive item, prices_include_tax, and round=true.');

    // Deleted product ($item->get_product() === false) or blank/non-HTTPS optional fields keep the item without failing.
    $minimalOrder = new PresentationFakeOrder();
    $minimalOrder->total = '47.00';
    $minimalOrder->items = array(new WC_Order_Item_Product('Deleted Catalog Item', 2, false));
    $minimalOrder->subtotals = array('24.50');
    paymos_renewal_setup($minimalOrder, array(
        array('invoice_id' => 'inv_deleted_prod', 'status' => 'awaiting_client', 'payment_url' => 'https://checkout.paymos.io/inv_deleted_prod'),
    ), $sent);
    $res2 = (new Gateway())->process_payment(100);
    assertSameValue('success', $res2['result'], 'Deleted product order item must still succeed.');
    assertSameValue(array(
        array(
            'title' => 'Deleted Catalog Item',
            'quantity' => 2,
            'unit_amount' => '24.50',
        ),
    ), $sent[0]['products'], 'Deleted product must keep required item fields and omit optional description/image_url.');

    $httpOrder = new PresentationFakeOrder();
    $httpOrder->total = '12.00';
    $httpOrder->items = array(new WC_Order_Item_Product('Notebook', 1, new PresentationFakeProduct('&nbsp;', '', 77)));
    $httpOrder->subtotals = array('12.00');
    paymos_renewal_setup($httpOrder, array(
        array('invoice_id' => 'inv_http_img', 'status' => 'awaiting_client', 'payment_url' => 'https://checkout.paymos.io/inv_http_img'),
    ), $sent);
    $GLOBALS['paymos_test_attachment_urls'] = array(77 => 'http://shop.example/insecure.png');
    $res3 = (new Gateway())->process_payment(100);
    assertSameValue('success', $res3['result'], 'Item with blank description and HTTP image must still succeed.');
    assertSameValue(array(
        array(
            'title' => 'Notebook',
            'quantity' => 1,
            'unit_amount' => '12.00',
        ),
    ), $sent[0]['products'], 'Blank description and non-HTTPS image_url must be omitted without failing the item.');
    unset($GLOBALS['paymos_test_attachment_urls']);
}

function test_gateway_sends_multi_item_cart_excluding_non_product_lines_and_applies_all_or_nothing_fallback()
{
    $order = new PresentationFakeOrder();
    $order->total = '42.50';
    $order->items = array(
        new WC_Order_Item_Product('Canvas', 2, new PresentationFakeProduct('Fine art print', '', 10)),
        new WC_Order_Item_Product('Notebook', 1, new PresentationFakeProduct('', '', 0)),
        new WC_Order_Item_Product('Flat rate shipping', 1, null, 'shipping'),
        new WC_Order_Item_Product('Handling fee', 1, null, 'fee'),
        new WC_Order_Item_Product('VAT 20%', 1, null, 'tax'),
        new WC_Order_Item_Product('SAVE10', 1, null, 'coupon'),
    );
    $order->subtotals = array('15.00', '12.50', '5.00', '2.00', '3.00', '-10.00');

    paymos_renewal_setup($order, array(
        array('invoice_id' => 'inv_cart', 'status' => 'awaiting_client', 'payment_url' => 'https://checkout.paymos.io/inv_cart'),
    ), $sent);
    $GLOBALS['paymos_test_attachment_urls'] = array(10 => 'https://shop.example/canvas.png');

    $result = (new Gateway())->process_payment(100);

    assertSameValue('success', $result['result'], 'Cart with shipping/fee/tax/coupon must succeed.');
    assertSameValue('42.5', $sent[0]['amount'], 'Native grand total must remain intact.');
    assertSameValue(array(
        array(
            'title' => 'Canvas',
            'quantity' => 2,
            'unit_amount' => '15.00',
            'description' => 'Fine art print',
            'image_url' => 'https://shop.example/canvas.png',
        ),
        array(
            'title' => 'Notebook',
            'quantity' => 1,
            'unit_amount' => '12.50',
        ),
    ), $sent[0]['products'], 'Shipping, fee, tax, and coupon lines must be excluded from products[].');

    // Exactly 50 items succeed; 51 items fall back all-or-nothing to amount-only.
    $fiftyOrder = new PresentationFakeOrder();
    $fiftyOrder->total = '50.00';
    for ($i = 1; $i <= 50; $i++) {
        $fiftyOrder->items[] = new WC_Order_Item_Product('Item ' . $i, 1, null);
        $fiftyOrder->subtotals[] = '1.00';
    }
    paymos_renewal_setup($fiftyOrder, array(
        array('invoice_id' => 'inv_50', 'status' => 'awaiting_client', 'payment_url' => 'https://checkout.paymos.io/inv_50'),
    ), $sent);
    assertSameValue('success', (new Gateway())->process_payment(100)['result'], '50 items must succeed.');
    assertSameValue(50, count($sent[0]['products']), '50 valid items must be transmitted.');

    $fiftyOneOrder = new PresentationFakeOrder();
    $fiftyOneOrder->total = '51.00';
    for ($i = 1; $i <= 51; $i++) {
        $fiftyOneOrder->items[] = new WC_Order_Item_Product('Item ' . $i, 1, null);
        $fiftyOneOrder->subtotals[] = '1.00';
    }
    paymos_renewal_setup($fiftyOneOrder, array(
        array('invoice_id' => 'inv_51', 'status' => 'awaiting_client', 'payment_url' => 'https://checkout.paymos.io/inv_51'),
    ), $sent);
    assertSameValue('success', (new Gateway())->process_payment(100)['result'], '51 items must still create invoice.');
    assertSameValue(false, array_key_exists('products', $sent[0]), '51 items must fall back all-or-nothing to amount-only.');

    // Fractional quantity or negative unit amount on one item triggers all-or-nothing fallback.
    $badCartOrder = new PresentationFakeOrder();
    $badCartOrder->total = '35.00';
    $badCartOrder->items = array(
        new WC_Order_Item_Product('Valid Shirt', 1, null),
        new WC_Order_Item_Product('Bulk Fabric', 1.5, null),
    );
    $badCartOrder->subtotals = array('20.00', '10.00');
    paymos_renewal_setup($badCartOrder, array(
        array('invoice_id' => 'inv_bad_qty', 'status' => 'awaiting_client', 'payment_url' => 'https://checkout.paymos.io/inv_bad_qty'),
    ), $sent);
    assertSameValue('success', (new Gateway())->process_payment(100)['result'], 'Fractional qty cart must fall back to amount-only.');
    assertSameValue(false, array_key_exists('products', $sent[0]), 'All-or-nothing fallback must omit partial products[] on fractional qty.');

    $negativeItemOrder = new PresentationFakeOrder();
    $negativeItemOrder->total = '15.00';
    $negativeItemOrder->items = array(
        new WC_Order_Item_Product('Valid Shirt', 1, null),
        new WC_Order_Item_Product('Adjustment', 1, null),
    );
    $negativeItemOrder->subtotals = array('20.00', '-5.00');
    paymos_renewal_setup($negativeItemOrder, array(
        array('invoice_id' => 'inv_neg_item', 'status' => 'awaiting_client', 'payment_url' => 'https://checkout.paymos.io/inv_neg_item'),
    ), $sent);
    assertSameValue('success', (new Gateway())->process_payment(100)['result'], 'Negative line subtotal must fall back to amount-only.');
    assertSameValue(false, array_key_exists('products', $sent[0]), 'All-or-nothing fallback must omit partial products[] on negative item price.');
    unset($GLOBALS['paymos_test_attachment_urls']);
}

function test_gateway_lost_response_retry_replays_persisted_products_even_if_order_items_change()
{
    $order = new PresentationFakeOrder();
    $order->total = '42.50';
    $order->items = array(
        new WC_Order_Item_Product('Studio "Pro" \\ v1', 2, new PresentationFakeProduct("Line 1\nLine 2 — Ω", '', 12)),
        new WC_Order_Item_Product('Priority &lt;Support&gt;', 1, null),
    );
    $order->subtotals = array('15.00', '12.50');

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
    $GLOBALS['paymos_test_attachment_urls'] = array(12 => 'https://shop.example/studio.webp');

    $sent = array();
    $lost = true;
    $GLOBALS['paymos_test_remote_handler'] = static function ($url, array $args) use (&$sent, &$lost) {
        $sent[] = json_decode((string) $args['body'], true);
        if ($lost) {
            return new WP_Error('timeout', 'Accepted by API; response lost.');
        }
        return array(
            'response' => array('code' => 201),
            'body' => json_encode(array('invoice_id' => 'inv_recovered', 'status' => 'awaiting_client', 'payment_url' => 'https://checkout.paymos.io/inv_recovered')),
            'headers' => array('content-type' => 'application/json'),
        );
    };

    $firstResult = (new Gateway())->process_payment(100);
    assertSameValue('failure', $firstResult['result'], 'First attempt with lost response reports failure.');
    assertSameValue('pending', $order->meta['_paymos_creation_intent'], 'Creation intent stays pending.');
    $originalProducts = $sent[0]['products'];
    assertSameValue(2, count($originalProducts), 'First POST sent both original products.');

    // Mutate order line items (keeping grand total 42.50) before retry.
    $order->items = array(new WC_Order_Item_Product('Changed Item', 1, new PresentationFakeProduct('New desc', '', 99)));
    $order->subtotals = array('42.50');
    $GLOBALS['paymos_test_attachment_urls'] = array(99 => 'https://shop.example/changed.webp');
    $lost = false;

    $retryResult = (new Gateway())->process_payment(100);
    assertSameValue('success', $retryResult['result'], 'Retry must recover the pending creation intent.');
    assertSameValue(2, count($sent), 'Retry must replay the exact pending POST without creating a second intent.');
    assertSameValue($sent[0], $sent[1], 'Retry POST body including products[] must be identical to the initial POST despite order item edits.');
    assertSameValue($originalProducts, $sent[1]['products'], 'Original products[] A must be preserved on retry.');
    assertSameValue('Priority <Support>', $sent[1]['products'][1]['title'], 'Decoded special characters in title must survive CreationIntent persistence.');
    unset($GLOBALS['paymos_test_attachment_urls']);
}

function test_creation_intent_rejects_invalid_products_before_persistence_or_http()
{
    paymos_reset_test_state();
    $order = new RenewableFakeOrder();
    $lock = new \Paymos\Plugin\WordPressOrderLock('woocommerce', 100);
    $lock->acquire();
    try {
        foreach (array(
            array(),
            array(array('title' => 'Item', 'quantity' => 1.5, 'unit_amount' => '10.00')),
            array(array('title' => 'Item', 'quantity' => 1, 'unit_amount' => '-1.00')),
            array(array('title' => 'Item', 'quantity' => 1, 'unit_amount' => '10.00', 'image_url' => 'http://insecure.example/a.png')),
            'not-an-array',
        ) as $badProducts) {
            $threw = false;
            try {
                \PaymosWooCommerce\CreationIntent::persist($order, array(
                    'project_id' => 'prj_123',
                    'external_order_id' => 'wc_100_abc123',
                    'amount' => '100.00',
                    'currency' => 'USD',
                    'products' => $badProducts,
                ), 'sandbox', $lock);
            } catch (RuntimeException $e) {
                $threw = true;
            }
            assertTrueValue($threw, 'CreationIntent::persist must fail closed on invalid products.');
        }
    } finally {
        $lock->release();
    }
}
