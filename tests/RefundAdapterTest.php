<?php

declare(strict_types=1);

use Paymos\Client;
use Paymos\ClientConfig;
use Paymos\Http\HttpResponse;
use Paymos\Http\TransportInterface;
use Paymos\Plugin\EffectClaim;
use Paymos\Plugin\RefundIntent;
use Paymos\Plugin\RefundStates;
use Paymos\Plugin\VerifiedRefund;
use PaymosWooCommerce\Gateway;
use PaymosWooCommerce\RefundAdapter;
use PaymosWooCommerce\RefundIntentStore;

// Reuse the plugin's runner and platform stubs. These are service tests, not native certification.
require_once __DIR__ . '/run.php';

final class RefundDatabaseFixture extends FakeWpdb
{
    public $last_error = '';
    public $intents = array();
    public $engine = 'InnoDB';
    public $inTransaction = false;
    public $snapshot;
    public $loseProjectionCommit = false;
    public $failWrite = false;
    public $commands = array();

    public function get_row($sql, $output = null)
    {
        $this->last_error = '';
        $q = $sql['query']; $a = $sql['args'];
        if (strpos($q, 'SHOW TABLE STATUS') === 0) { return array('Engine' => $this->engine); }
        if (strpos($q, 'SELECT payload') !== 0) { return parent::get_row($sql, $output); }
        foreach ($this->intents as $row) {
            if (strpos($q, 'installation_id =') !== false) {
                if ($row['installation_id'] === $a[1] && $row['request_token'] === $a[2]) { return $row; }
            } elseif ($row[$a[1]] === $a[2]) { return $row; }
        }
        return null;
    }

    public function get_results($sql, $output = null)
    {
        $this->last_error = '';
        $q = $sql['query']; $a = $sql['args'];
        if (strpos($q, 'SHOW TABLE STATUS') === 0) { return array(array('Engine' => $this->engine)); }
        if (strpos($q, 'SELECT comment_ID') === 0) {
            $rows = array();
            foreach ($GLOBALS['refund_comments'] as $id => $note) {
                if ((string) $note->comment_post_ID === (string) $a[1] && strpos($note->comment_content, $a[3]) !== false) {
                    $rows[] = array('comment_ID' => $id);
                }
            }
            return $rows;
        }
        $rows = array_values(array_filter($this->intents, static function ($row) use ($q, $a) {
            return strpos($q, 'native_payment_key =') !== false ? $row['native_payment_key'] === $a[1]
                : $row['next_check'] <= $a[1] && strcmp($row['operation_id'], $a[2]) > 0;
        }));
        usort($rows, static function ($left, $right) { return strcmp($left['operation_id'], $right['operation_id']); });
        return isset($a[3]) ? array_slice($rows, 0, $a[3]) : $rows;
    }

    public function insert($table, array $data, array $format = array())
    {
        if ($table !== $this->prefix . 'paymos_refund_intents') { return parent::insert($table, $data, $format); }
        if ($this->failWrite) { return false; }
        foreach ($this->intents as $old) {
            if ($old['operation_id'] === $data['operation_id'] || $old['external_order_id'] === $data['external_order_id']
                || ($old['installation_id'] === $data['installation_id'] && $old['request_token'] === $data['request_token'])) { return false; }
        }
        $this->intents[$data['operation_id']] = $data;
        return 1;
    }

    public function update($table, array $data, array $where, array $format = array(), array $whereFormat = array())
    {
        if ($table !== $this->prefix . 'paymos_refund_intents') { return parent::update($table, $data, $where, $format, $whereFormat); }
        if ($this->failWrite) { return false; }
        $row = $this->intents[$where['operation_id']] ?? null;
        if ($row === null || $row['version'] !== $where['version']) { return 0; }
        if (isset($data['withdrawal_id'])) {
            foreach ($this->intents as $other) {
                if ($other['operation_id'] !== $where['operation_id'] && $other['withdrawal_id'] === $data['withdrawal_id']) { return false; }
            }
        }
        $this->intents[$where['operation_id']] = array_replace($row, $data);
        return 1;
    }

    public function transactionCommand($command, $force)
    {
        assertSameValue(true, $force, 'Native transaction helper must be forced.');
        $this->last_error = '';
        $this->commands[] = $command;
        if ($command === 'start') {
            assertSameValue(false, $this->inTransaction, 'HTTP must never nest a native transaction.');
            $this->inTransaction = true;
            $this->snapshot = serialize(array($this->intents, $GLOBALS['paymos_test_wc_orders'], $GLOBALS['refund_comments'], $GLOBALS['refund_stock']));
        } elseif ($command === 'commit') {
            $this->inTransaction = false;
            $this->snapshot = null;
            if ($this->loseProjectionCommit && $GLOBALS['refund_native_writes'] > 0) {
                $this->loseProjectionCommit = false;
                $this->last_error = 'simulated_commit_ack_lost';
            }
        } elseif ($this->snapshot !== null) {
            list($this->intents, $GLOBALS['paymos_test_wc_orders'], $GLOBALS['refund_comments'], $GLOBALS['refund_stock']) = unserialize($this->snapshot);
            $this->snapshot = null;
            $this->inTransaction = false;
        }
    }
}

final class RefundOrderFixture extends FakeOrder
{
    public $refunds = array();
    public $refunded = '0';
    public $method = 'paymos';
    public $items = array();
    public $savedStatus = 'processing';
    private $pendingStatus;
    private $statusTransition;
    public function get_type() { return 'shop_order'; }
    public function get_payment_method() { return $this->method; }
    public function get_total_refunded() { return $this->refunded; }
    public function get_refunds() { return $this->refunds; }
    public function get_items($types = array()) { return $this->items; }
    public function get_status() { return $this->pendingStatus === null ? $this->savedStatus : $this->pendingStatus; }
    public function get_changes()
    {
        return $this->pendingStatus !== null && $this->pendingStatus !== $this->savedStatus
            ? array('status' => $this->pendingStatus) : array();
    }
    public function set_status($status, $note = '', $manual = false)
    {
        $status = preg_replace('/^wc-/', '', $status);
        if ($status !== $this->get_status()) { $this->statusTransition = $status; }
        $this->pendingStatus = $status;
    }
    public function update_status($status, $note = '', $manual = false)
    {
        $this->set_status($status, $note, $manual);
        $this->save();
        return true;
    }
    public function save()
    {
        try {
            do_action('woocommerce_before_order_object_save', $this, $this->get_data_store());
            $GLOBALS['refund_order_writes']++;
            $this->savedStatus = $this->get_status();
            $this->pendingStatus = null;
        } catch (Exception $error) {
            $this->add_order_note($error->getMessage());
        }
        $transition = $this->statusTransition;
        $this->statusTransition = null;
        if ($transition !== null) {
            $this->paid = in_array($transition, array('processing', 'completed'), true);
            do_action('woocommerce_order_status_' . $transition, $this->get_id(), $this);
        }
        return $this->get_id();
    }
    public function add_order_note($note, $customer = false)
    {
        $id = count($GLOBALS['refund_comments']) + 1;
        $GLOBALS['refund_comments'][$id] = (object) array('comment_post_ID' => $this->get_id(), 'comment_content' => $note);
        $this->notes[] = $note;
        assertSameValue(false, $customer, 'Overpayment note must not send customer notification.');
        return $id;
    }
}

final class RefundDocumentFixture
{
    private $id;
    private $parent;
    private $amount;
    private $currency;
    public $meta = array();
    public function __construct($id, $parent, $amount, $currency) { $this->id = $id; $this->parent = $parent; $this->amount = $amount; $this->currency = $currency; }
    public function get_id() { return $this->id; }
    public function get_parent_id() { return $this->parent; }
    public function get_amount() { return $this->amount; }
    public function get_currency() { return $this->currency; }
    public function get_meta($key, $single = true) { return $this->meta[$key] ?? ''; }
    public function update_meta_data($key, $value) { $this->meta[$key] = $value; }
    public function get_data_store() { return new NativeOrderStoreFixture(); }
    public function read_meta_data($force = false) {}
    public function save()
    {
        try {
            do_action('woocommerce_before_order_refund_object_save', $this, $this->get_data_store());
            $GLOBALS['refund_document_saves']++;
            if (!$this->id) {
                $this->id = 1000 + ++$GLOBALS['refund_native_writes'];
                $GLOBALS['paymos_test_wc_orders'][] = $this;
            }
        } catch (Exception $error) {
            $GLOBALS['refund_save_errors'][] = $error->getMessage();
        }
        return $this->id;
    }
}

final class RefundItemFixture
{
    public function get_quantity() { return 2; }
    public function get_total() { return '100'; }
    public function get_taxes() { return array('total' => array()); }
}

final class RefundApiFixture implements TransportInterface
{
    public $requests = array();
    public $refunds = array();
    public $timeout = false;
    public $status = 'created';
    public $loseLockOnPost = false;
    public $replaceGet;
    public $requireCommittedIntent = false;
    public $invoice;

    public function __construct()
    {
        $this->invoice = array('invoice_id' => 'inv_123', 'project_id' => 'prj_123', 'is_test' => true, 'status' => 'paid_over',
            'order' => array('external_id' => 'wc_100', 'amount' => '100', 'currency' => 'USD'),
            'refund_summary' => array('can_refund_full' => true, 'can_refund_overpayment' => true,
                'full_refund_amount' => '120', 'overpayment_refund_amount' => '20'));
    }

    public function request($method, $url, array $headers, $body, $timeoutSeconds)
    {
        global $wpdb;
        assertSameValue(false, $wpdb->inTransaction, 'No provider HTTP inside DB transaction.');
        if ($this->requireCommittedIntent) { assertTrueValue(count($wpdb->intents) > 0, 'Committed intent precedes every operation HTTP call, including preflight GET.'); }
        $this->requests[] = array('method' => $method, 'url' => $url, 'body' => $body);
        if ($method === 'POST') {
            $payload = json_decode($body, true);
            assertSameValue(array('invoice_id', 'mode', 'external_order_id'), array_keys($payload), 'SDK mode-only wire contract.');
            $key = $payload['external_order_id'];
            $saved = (new RefundIntentStore())->findByExternalId($key);
            assertSameValue(RefundStates::UNKNOWN, $saved->cashState(), 'Unknown is durably committed before cash POST.');
            if (!isset($this->refunds[$key])) {
                $hasOver = count(array_filter($this->refunds, static function ($r) { return $r['mode'] === 'overpayment' && $r['status'] === 'completed'; })) > 0;
                $this->refunds[$key] = array('withdrawal_id' => 'wd_' . (count($this->refunds) + 1), 'invoice_id' => 'inv_123',
                    'project_id' => 'prj_123', 'is_test' => true, 'mode' => $payload['mode'], 'external_order_id' => $key,
                    'status' => $this->status, 'amount' => $payload['mode'] === 'overpayment' ? '20' : ($hasOver ? '100' : '120'),
                    'currency' => 'USDT', 'network' => 'Tron', 'first_sender' => 'TFirstSender', 'created_at' => time());
            }
            if ($this->loseLockOnPost) { $wpdb->connectionId = '2'; }
            if ($this->timeout) { throw new RuntimeException('simulated_response_lost_after_commit'); }
            $response = array('withdrawal_id' => $this->refunds[$key]['withdrawal_id']);
        } elseif (parse_url($url, PHP_URL_PATH) === '/v1/refunds') {
            $response = array('items' => array_values($this->refunds), 'next_cursor' => null);
        } elseif (strpos($url, '/v1/refunds/') !== false) {
            $response = null;
            foreach ($this->refunds as $refund) { if (substr($url, -strlen($refund['withdrawal_id'])) === $refund['withdrawal_id']) { $response = $refund; } }
            if ($this->replaceGet !== null) { $response = array_replace($response, $this->replaceGet); }
            if ($response === null) { return new HttpResponse(404, '{}', array()); }
        } else {
            $response = $this->invoice;
        }
        return new HttpResponse(200, json_encode($response), array('Cache-Control' => 'private, no-store'));
    }

    public function posts(): array { return array_values(array_filter($this->requests, static function ($r) { return $r['method'] === 'POST'; })); }
    public function setStatus($status, $mode = null)
    {
        foreach ($this->refunds as $key => $refund) { if ($mode === null || $refund['mode'] === $mode) { $this->refunds[$key]['status'] = $status; } }
    }
}

function wc_transaction_query($command, $force = false) { $GLOBALS['wpdb']->transactionCommand($command, $force); }
function wc_get_price_decimals() { return 2; }
function wp_generate_uuid4() { return sprintf('00000000-0000-4000-8000-%012d', ++$GLOBALS['refund_uuid']); }
function get_comment($id) { return $GLOBALS['refund_comments'][$id] ?? null; }

function WC()
{
    return new class {
        public $cart;
        public function __construct() { $this->cart = new class { public function empty_cart() {} }; }
        public function mailer() { return $GLOBALS['refund_mailer']; }
    };
}

final class RefundMailFixture
{
    public $refund;
    public $calls = 0;
    public $outcome = true;
    public function get_emails() { return array('WC_Email_Customer_Refunded_Order' => $this); }
    public function trigger_full($orderId, $refundId)
    {
        $this->refund = wc_get_order($refundId);
        $operation = $this->refund->get_meta('_paymos_refund_operation_id');
        $intent = (new RefundIntentStore())->getIntent($operation);
        assertSameValue(false, $GLOBALS['wpdb']->inTransaction, 'Mail is outside native DB transaction.');
        assertSameValue('started', $intent->toArray()['effects']['customer_refund:1'], 'Started is committed before native mail trigger.');
        assertSameValue(true, RefundAdapter::emailEnabled(true, wc_get_order($orderId), $this), 'Exact module dispatch context permits this refund email.');
        $this->calls++;
        if ($this->outcome === 'crash') { throw new RuntimeException('simulated_smtp_ack_lost'); }
        if ($this->outcome !== null) { apply_filters('woocommerce_email_sent', $this->outcome, 'customer_refunded_order', $this); }
    }
}

final class RefundCallbackRequestFixture
{
    private $body;
    private $header;
    public function __construct(array $payload, $secret = 'whsec_test')
    {
        $this->body = json_encode($payload);
        $this->header = paymos_signed_header($secret, $this->body, time());
    }
    public function get_body() { return $this->body; }
    public function get_header($key) { return $this->header; }
    public function get_route() { return '/paymos/v1/webhook'; }
    public function get_method() { return 'POST'; }
}

function wc_create_refund($args)
{
    $args += array('refund_payment' => false, 'restock_items' => false, 'reason' => '', 'line_items' => array());
    $order = wc_get_order($args['order_id']);
    $refund = new RefundDocumentFixture(0, $order->get_id(), $args['amount'], $order->get_currency());
    // Early saves expose guards that only reject at the create-refund action.
    $refund->save();
    $refund->save();
    do_action('woocommerce_create_refund', $refund, $args);
    $refund->save();
    if ($args['refund_payment']) { $GLOBALS['refund_gateway_calls']++; }
    if ($order->get_payment_method() === 'paymos') {
        assertSameValue(false, $args['refund_payment'], 'Native projection must be offline.');
        assertSameValue(true, $GLOBALS['wpdb']->inTransaction, 'Native writes must share the module transaction.');
        assertTrueValue($refund->get_meta('_paymos_refund_operation_id') !== '', 'Authorized projection retains its CRUD marker.');
    }
    $order->refunds[] = $refund;
    $order->refunded = $args['amount'];
    if ($args['restock_items']) { $GLOBALS['refund_stock'] += 2; }
    if ($GLOBALS['refund_fail_native']) { throw new RuntimeException('simulated_native_write_failure'); }
    if ((float) $args['amount'] >= (float) $order->get_total()) { $order->update_status('refunded'); }
    return $refund;
}

function wc_order_fully_refunded($orderId)
{
    $order = wc_get_order($orderId);
    $remaining = (float) $order->get_total() - (float) $order->get_total_refunded();
    if ($remaining > 0) { wc_create_refund(array('order_id' => $orderId, 'amount' => (string) $remaining)); }
}

add_action('woocommerce_order_status_refunded', 'wc_order_fully_refunded');

function refund_setup(&$api = null, &$store = null, &$config = null): RefundAdapter
{
    paymos_reset_test_state();
    $GLOBALS['wpdb'] = new RefundDatabaseFixture();
    $GLOBALS['refund_comments'] = array();
    $GLOBALS['refund_native_writes'] = 0;
    $GLOBALS['refund_document_saves'] = 0;
    $GLOBALS['refund_order_writes'] = 0;
    $GLOBALS['refund_gateway_calls'] = 0;
    $GLOBALS['refund_save_errors'] = array();
    $GLOBALS['paymos_test_die_status'] = null;
    $GLOBALS['refund_stock'] = 8;
    $GLOBALS['refund_uuid'] = 0;
    $GLOBALS['refund_fail_native'] = false;
    $GLOBALS['refund_mailer'] = new RefundMailFixture();
    paymos_set_option('paymos_woocommerce_refund_installation', '11111111-1111-4111-8111-111111111111');
    paymos_set_option('woocommerce_customer_refunded_order_settings', array('enabled' => 'no'));
    $order = new RefundOrderFixture();
    $order->paid = true;
    $order->meta = array('_paymos_invoice_id' => 'inv_123', '_paymos_project_id' => 'prj_123', '_paymos_environment' => 'sandbox',
        '_paymos_external_order_id' => 'wc_100', '_paymos_invoice_amount' => '100', '_paymos_invoice_currency' => 'USD',
        '_paymos_applied_paid_invoice_id' => 'inv_123', '_paymos_last_status' => 'paid_over');
    $api = new RefundApiFixture();
    $store = new RefundIntentStore();
    $config = array('project_id' => 'prj_123', 'capabilities' => array('invoice_refunds_v1'), 'refund_contract_version' => 1, 'refunds_enabled' => true);
    $client = new Client(new ClientConfig('pk_test_key', 'sk_test_secret', 'https://api.paymos.test'), $api);
    return new RefundAdapter($store, static function ($environment) use ($client) {
        assertSameValue('sandbox', $environment, 'Use the saved environment.');
        return $client;
    }, static function () use (&$config) { return $config; });
}

function refund_full_input(): array
{
    return array('api_refund' => 'true', 'refund_amount' => '100.00', 'refunded_amount' => '0', 'refund_reason' => '', 'restock_refunded_items' => 'false');
}

function refund_assert_rejected(callable $action, $reason)
{
    try { $action(); } catch (RuntimeException $error) {
        assertSameValue($reason, $error->getMessage(), 'Precise refusal reason.');
        return;
    }
    throw new RuntimeException('Expected rejection: ' . $reason);
}

function refund_assert_native_refused(callable $action, RefundApiFixture $api)
{
    $refused = false;
    try { $action(); } catch (Throwable $error) {
        assertSameValue('Nothing was refunded. Open the order in WooCommerce admin and refund it there.',
            $error->getMessage(), 'Native refusals reuse the gateway refund message.');
        $refused = true;
    }
    assertSameValue(true, $refused, 'The native operation must stop.');
    assertSameValue(403, $GLOBALS['paymos_test_die_status'], 'The native operation must return an explicit refusal.');
    assertSameValue(array(), $api->requests, 'Refusal must precede provider HTTP.');
    assertSameValue(array(), $GLOBALS['paymos_test_http_requests'], 'Refusal must precede WordPress HTTP.');
    assertSameValue(0, $GLOBALS['refund_gateway_calls'], 'Refusal must precede gateway invocation.');
    assertSameValue(0, $GLOBALS['refund_native_writes'], 'No temporary refund may be inserted.');
    assertSameValue(0, $GLOBALS['refund_document_saves'], 'No refund may be saved.');
    assertSameValue(0, $GLOBALS['refund_order_writes'], 'The order must not be saved.');
    assertSameValue(array(), $GLOBALS['refund_comments'], 'No failure or transition note may be written.');
    assertSameValue(array(), $GLOBALS['wpdb']->intents, 'Native refusal must not create a Paymos intent.');
    assertSameValue(array(), wc_get_order(100)->get_refunds(), 'The order must retain its refund list.');
    assertSameValue('0', wc_get_order(100)->get_total_refunded(), 'The refunded total must stay unchanged.');
    assertSameValue('processing', wc_get_order(100)->savedStatus, 'The persisted status must stay paid.');
    assertSameValue(8, $GLOBALS['refund_stock'], 'Refusal must not restock.');
}

function refund_test_native_refund_api_refuses_offline_and_online_before_first_save()
{
    foreach (array(false, true) as $refundPayment) {
        foreach (array('10', '100') as $amount) {
            refund_setup($api);
            wc_get_order(100)->meta = array();
            refund_assert_native_refused(static function () use ($refundPayment, $amount) {
                wc_create_refund(array('order_id' => 100, 'amount' => $amount,
                    'refund_payment' => $refundPayment, 'restock_items' => true));
            }, $api);
        }
    }
}

function refund_test_native_refund_from_cron_and_direct_crud_is_refused()
{
    refund_setup($api);
    add_action('paymos_test_external_refund_cron', static function () {
        wc_create_refund(array('order_id' => 100, 'amount' => '100', 'refund_payment' => false));
    });
    refund_assert_native_refused(static function () { do_action('paymos_test_external_refund_cron'); }, $api);
    refund_setup($api);
    refund_assert_native_refused(static function () {
        $refund = new RefundDocumentFixture(0, 100, '100', 'USD');
        $refund->save();
    }, $api);
}

function refund_test_native_refund_api_and_crud_preserve_other_gateways()
{
    refund_setup($api);
    wc_get_order(100)->method = 'cod';
    $refund = wc_create_refund(array('order_id' => 100, 'amount' => '100', 'restock_items' => true));
    assertSameValue(1, $GLOBALS['refund_native_writes'], 'An offline refund for another gateway must still be created.');
    assertSameValue('refunded', wc_get_order(100)->savedStatus, 'A full offline refund still changes the order status.');
    assertSameValue('100', wc_get_order(100)->get_total_refunded(), 'Another gateway retains its refunded total.');
    assertSameValue('', $refund->get_meta('_paymos_refund_operation_id'), 'Another gateway must not receive a Paymos marker.');
    assertSameValue(10, $GLOBALS['refund_stock'], 'Another gateway retains requested restocking.');
    $direct = new RefundDocumentFixture(0, 100, '10', 'USD');
    $direct->save();
    assertSameValue(2, $GLOBALS['refund_native_writes'], 'Direct CRUD for another gateway must still save.');
    assertSameValue(array(), $api->requests, 'Other gateways must not contact Paymos.');
}

function refund_test_native_refunded_status_refuses_update_and_set_then_save()
{
    foreach (array('refunded', 'wc-refunded') as $status) {
        refund_setup($api);
        refund_assert_native_refused(static function () use ($status) { wc_get_order(100)->update_status($status); }, $api);
        refund_setup($api);
        refund_assert_native_refused(static function () use ($status) {
            $order = wc_get_order(100);
            $order->set_status($status);
            $order->save();
        }, $api);
    }
}

function refund_test_native_status_guard_preserves_other_gateways_and_paid_transitions()
{
    refund_setup($api);
    wc_get_order(100)->method = 'cod';
    wc_get_order(100)->update_status('refunded');
    assertSameValue('refunded', wc_get_order(100)->savedStatus, 'Another gateway retains its status transition.');
    assertSameValue(1, $GLOBALS['refund_native_writes'], 'The core status callback still creates another gateway refund.');
    assertSameValue('100', wc_get_order(100)->get_total_refunded(), 'The core callback retains its accounting effect.');
    assertSameValue(array(), $api->requests, 'Another gateway status must not contact Paymos.');
    refund_setup($api);
    wc_get_order(100)->update_status('completed');
    assertSameValue('completed', wc_get_order(100)->savedStatus, 'Paymos fulfillment transitions remain allowed.');
    assertSameValue(0, $GLOBALS['refund_native_writes'], 'Fulfillment must not create a refund.');
    assertSameValue(array(), $api->requests, 'Fulfillment must not contact Paymos.');
}

function refund_test_completed_projection_allows_status_and_does_not_leave_native_bypass_open()
{
    $adapter = refund_setup($api);
    $api->status = 'completed';
    $intent = $adapter->request('100', 'full', 'token_native_guards_01', refund_full_input());
    assertSameValue('applied', $intent->projectionState(), 'Verified Paymos completion must still project.');
    assertSameValue('refunded', wc_get_order(100)->savedStatus, 'Paymos completion must still persist the refunded status.');
    assertSameValue(1, $GLOBALS['refund_native_writes'], 'The core status callback must not duplicate the authorized document.');
    wc_get_order(100)->save();
    $adapter->reconcile($intent->operationId());
    assertSameValue(1, $GLOBALS['refund_native_writes'], 'Saving and reconciling a completed refund must remain idempotent.');
    refund_setup($api);
    refund_assert_native_refused(static function () {
        wc_create_refund(array('order_id' => 100, 'amount' => '100'));
    }, $api);
}

function refund_test_saved_intent_cron_can_complete_with_native_guards_enabled()
{
    $adapter = refund_setup($api, $store);
    $credentials = paymos_test_credentials();
    $credentials['sandbox']['project_id'] = 'prj_123';
    paymos_store_credentials($credentials);
    $intent = $adapter->request('100', 'full', 'token_guard_cron_full_01', refund_full_input());
    $GLOBALS['wpdb']->intents[$intent->operationId()]['next_check'] = 0;
    $api->setStatus('completed');
    $GLOBALS['paymos_test_remote_handler'] = static function ($url, array $args) use ($api) {
        $response = $api->request($args['method'], $url, $args['headers'], $args['body'], $args['timeout']);
        return array('response' => array('code' => $response->statusCode()),
            'body' => $response->body(), 'headers' => $response->headers());
    };
    do_action(RefundAdapter::HOOK);
    assertSameValue('applied', $store->getIntent($intent->operationId())->projectionState(), 'Cron must project a verified saved intent.');
    assertSameValue('refunded', wc_get_order(100)->savedStatus, 'Cron must retain its authorized status transition.');
    assertSameValue(1, $GLOBALS['refund_native_writes'], 'Cron must create exactly one native document.');
    assertSameValue(1, count($api->posts()), 'Cron must not repeat the accepted cash request.');
    assertSameValue(null, $GLOBALS['paymos_test_die_status'], 'Saved-intent cron must not enter the native refusal path.');
}

function refund_test_projection_context_cannot_authorize_another_paymos_order()
{
    $adapter = refund_setup($api);
    $order = wc_get_order(100);
    $other = new RefundOrderFixture('100', 'USD', 200);
    $GLOBALS['paymos_test_wc_orders'] = array($order, $other);
    $blocked = array();
    $callback = static function ($refund) use (&$blocked) {
        if ($refund->get_parent_id() !== 100) { return; }
        $before = $GLOBALS['refund_document_saves'];
        try {
            wc_create_refund(array('order_id' => 200, 'amount' => '100'));
        } catch (Error $error) {
            assertSameValue($before, $GLOBALS['refund_document_saves'], 'Another order must be refused before a refund save.');
            $blocked[] = 'refund';
        }
        try {
            wc_get_order(200)->update_status('refunded');
        } catch (Error $error) {
            assertSameValue('processing', wc_get_order(200)->savedStatus, 'Another order must be refused before its status save.');
            $blocked[] = 'status';
        }
    };
    add_action('woocommerce_before_order_refund_object_save', $callback, 1);
    try {
        $api->status = 'completed';
        $intent = $adapter->request('100', 'full', 'token_guard_scope_full_01', refund_full_input());
        assertSameValue('applied', $intent->projectionState(), 'The authorized order must still complete.');
        assertTrueValue(in_array('refund', $blocked, true) && in_array('status', $blocked, true), 'Both other-order native paths must be refused.');
        assertSameValue(array(), $other->get_refunds(), 'Another order must retain its refund list.');
        assertSameValue(1, $GLOBALS['refund_native_writes'], 'Only the authorized order may receive a refund.');
        assertSameValue(1, count($api->posts()), 'Other-order native calls must not initiate cash.');
    } finally {
        unset($GLOBALS['paymos_actions']['woocommerce_before_order_refund_object_save'][1]);
    }
}

function refund_test_ajax_permission_refusals_return_http_200_with_error_text()
{
    foreach (array('nativeAjax', 'managedAjax') as $handler) {
        foreach (array('edit_shop_orders', 'edit_shop_order') as $capability) {
            refund_setup($api);
            $_POST = array('order_id' => 100);
            $GLOBALS['paymos_test_capabilities'][$capability] = false;
            RefundAdapter::$handler();
            assertSameValue(200, $GLOBALS['paymos_test_json_status'], $handler . ' permission refusal reaches the success callback.');
            assertSameValue(array('success' => false, 'data' => array('error' => 'You do not have permission to refund this order.')),
                $GLOBALS['paymos_test_json_response'], $handler . ' preserves the permission error envelope and text.');
            assertSameValue(0, count($api->requests), 'Permission refusal never reaches the provider.');
            assertSameValue(0, count($GLOBALS['wpdb']->intents), 'Permission refusal never creates an intent.');
        }
    }
}

function refund_test_ajax_nonce_refusals_return_http_200_with_error_text()
{
    foreach (array('nativeAjax', 'managedAjax') as $handler) {
        refund_setup($api);
        $_POST = array('order_id' => 100);
        $GLOBALS['paymos_test_ajax_nonce_valid'] = false;
        RefundAdapter::$handler();
        assertSameValue(200, $GLOBALS['paymos_test_json_status'], $handler . ' nonce refusal reaches the success callback.');
        assertSameValue(array('success' => false, 'data' => array('error' => 'You do not have permission to refund this order.')),
            $GLOBALS['paymos_test_json_response'], $handler . ' rejects an invalid nonce with an error envelope.');
        assertSameValue(0, count($api->requests), 'Nonce refusal never reaches the provider.');
        assertSameValue(0, count($GLOBALS['wpdb']->intents), 'Nonce refusal never creates an intent.');
    }
}

function refund_test_ajax_request_refusals_return_http_200_with_error_text()
{
    foreach (array('nativeAjax', 'managedAjax') as $handler) {
        refund_setup($api);
        $_POST = array('order_id' => 100, 'paymos_refund_token' => 'invalid');
        RefundAdapter::$handler();
        assertSameValue(200, $GLOBALS['paymos_test_json_status'], $handler . ' request refusal reaches the success callback.');
        assertSameValue(array('success' => false, 'data' => array('error' => 'The refund was not started. With Debug logging on, the reason is written to the WooCommerce logs.')),
            $GLOBALS['paymos_test_json_response'], $handler . ' preserves the request error envelope and text.');
        assertSameValue(0, count($api->requests), 'Invalid token never reaches the provider.');
        assertSameValue(0, count($GLOBALS['wpdb']->intents), 'Invalid token never creates an intent.');
    }
}

function refund_test_120_over20_then_full100_keeps_cash_and_accounting_separate()
{
    $adapter = refund_setup($api, $store);
    $api->status = 'completed';
    $over = $adapter->request('100', 'overpayment', 'token_overpayment_01', array());
    assertSameValue('applied', $over->projectionState(), 'Cash note projected.');
    $order = wc_get_order(100);
    assertSameValue(true, $order->is_paid(), 'Overpayment keeps order paid.');
    assertSameValue('0', $order->get_total_refunded(), 'Overpayment has no native refunded total.');
    assertSameValue(8, $GLOBALS['refund_stock'], 'Overpayment never restocks.');
    assertSameValue(1, count($GLOBALS['refund_comments']), 'One cash note.');
    $adapter->reconcile($over->operationId());
    assertSameValue(1, count($GLOBALS['refund_comments']), 'Replay reuses note.');
    $full = $adapter->request('100', 'full', 'token_full_refund_01', refund_full_input());
    assertSameValue('100', $full->observation()->principalDecimal(), 'Remaining server cash after over20.');
    assertSameValue('100', wc_get_order(100)->get_total_refunded(), 'Native full is original paid total.');
    assertSameValue(1, $GLOBALS['refund_native_writes'], 'Exactly one native financial document.');
    assertSameValue(2, count($api->refunds), 'One Withdrawal for each distinct mode intent.');
    assertSameValue('10000', $full->fullDraft()['amount_minor'], 'Native minor units preserved without FX.');
    $api->setStatus('failed', 'overpayment');
    $adapter->reconcile($over->operationId());
    assertSameValue('completed', $store->getIntent($full->operationId())->cashState(), 'Old mode callback cannot overwrite full state.');
    assertSameValue('native_review', $store->getIntent($over->operationId())->projectionState(), 'Old note retains rewind warning.');
    assertSameValue(1, count($GLOBALS['refund_comments']), 'Reorg keeps note history.');
}

function refund_test_pending_creates_no_native_document_and_fresh_get_completes_full()
{
    $adapter = refund_setup($api, $store);
    $api->requireCommittedIntent = true;
    $intent = $adapter->request('100', 'full', 'token_full_pending_01', refund_full_input());
    assertSameValue('created', $intent->cashState(), 'Accepted cash pending.');
    assertSameValue(0, $GLOBALS['refund_native_writes'], 'No premature financial success.');
    $api->setStatus('completed');
    $adapter->reconcile($intent->operationId());
    $adapter->reconcile($intent->operationId());
    assertSameValue(1, $GLOBALS['refund_native_writes'], 'Verified full cash completion projects once.');
    assertSameValue('120', $store->getIntent($intent->operationId())->observation()->principalDecimal(), 'Server full120 differs from native100.');
    assertSameValue('100', wc_get_order(100)->refunded, 'No crypto/native allocation.');
}

function refund_test_partial_manual_and_ambiguous_native_inputs_never_post()
{
    foreach (array('partial', 'manual', 'refunded', 'unpaid', 'snapshot', 'mapping', 'items') as $case) {
        $adapter = refund_setup($api);
        $input = refund_full_input();
        if ($case === 'partial') { $input['refund_amount'] = '50'; }
        if ($case === 'manual') { $input['api_refund'] = 'false'; }
        if ($case === 'refunded') { wc_get_order(100)->refunded = '1'; }
        if ($case === 'unpaid') { wc_get_order(100)->paid = false; }
        if ($case === 'snapshot') { wc_get_order(100)->set_total('101'); $input['refund_amount'] = '101'; }
        if ($case === 'mapping') { wc_get_order(100)->meta['_paymos_applied_paid_invoice_id'] = ''; }
        if ($case === 'items') { $input['line_item_qtys'] = '{"99":1}'; }
        $failed = false;
        try { $adapter->request('100', 'full', 'token_full_rejected_01', $input); } catch (RuntimeException $error) { $failed = true; }
        assertSameValue(true, $failed, 'Unsafe native input rejected: ' . $case);
        assertSameValue(0, count($api->posts()), 'No cash POST for ' . $case);
    }
}

function refund_test_capability_gate_and_nontransactional_tables_fail_before_http_post()
{
    $adapter = refund_setup($api, $store, $config);
    $config['capabilities'] = array();
    refund_assert_rejected(static function () use ($adapter) { $adapter->request('100', 'overpayment', 'token_over_rejected_01', array()); }, 'refund_reconnect_required');
    assertSameValue(0, count($api->posts()), 'Missing capability cannot send money.');
    $config['capabilities'] = array('invoice_refunds_v1');
    $config['refunds_enabled'] = true;
    $GLOBALS['wpdb']->engine = 'MyISAM';
    refund_assert_rejected(static function () use ($adapter) { $adapter->request('100', 'overpayment', 'token_over_rejected_01', array()); }, 'refund_transactional_tables_required');
    assertSameValue(0, count($api->posts()), 'Nontransactional storage cannot send money.');
}

function refund_test_unknown_replays_same_key_and_mode_even_after_new_gate_disabled()
{
    $adapter = refund_setup($api, $store, $config);
    $api->timeout = true;
    $intent = $adapter->request('100', 'full', 'token_full_unknown_01', refund_full_input());
    assertSameValue('unknown', $intent->cashState(), 'Lost response is unknown, never failed.');
    refund_assert_rejected(static function () use ($adapter) { $adapter->request('100', 'overpayment', 'token_full_unknown_01', array()); }, 'refund_request_conflict');
    refund_assert_rejected(static function () use ($adapter) { $adapter->request('100', 'full', 'token_new_conflict_01', refund_full_input()); }, 'refund_payment_has_unresolved_intent');
    $api->timeout = false;
    $api->setStatus('completed');
    $config['refunds_enabled'] = false;
    $recovered = $adapter->request('100', 'full', 'token_full_unknown_01', refund_full_input());
    assertSameValue($intent->operationId(), $recovered->operationId(), 'Same durable operation after response loss.');
    assertSameValue('applied', $recovered->projectionState(), 'Recovery survives new-create gate disable.');
    $bodies = array_column($api->posts(), 'body');
    assertSameValue(1, count(array_unique($bodies)), 'Every replay has exactly the original key and payload.');
    assertSameValue(1, count($api->refunds), 'No extra provider operation.');
}

function refund_test_write_failure_prevents_post_and_binding_is_write_once()
{
    $adapter = refund_setup($api, $store);
    $GLOBALS['wpdb']->failWrite = true;
    refund_assert_rejected(static function () use ($adapter) { $adapter->request('100', 'overpayment', 'token_over_failed_db_01', array()); }, 'refund_request_conflict');
    assertSameValue(0, count($api->posts()), 'Uncommitted intent never reaches POST.');
    $GLOBALS['wpdb']->failWrite = false;
    $intent = $adapter->request('100', 'overpayment', 'token_over_binding_01', array());
    $store->withPaymentLock('100', static function () use ($store, $intent) {
        assertSameValue('same', $store->bindWithdrawal($intent->operationId(), 'wd_1'), 'Binding replay.');
        assertSameValue('conflict', $store->bindWithdrawal($intent->operationId(), 'wd_foreign'), 'Write-once binding.');
    });
    assertSameValue('wd_1', $store->getIntent($intent->operationId())->withdrawalId(), 'Conflicting binding does not overwrite.');
    assertSameValue('native_review', $store->getIntent($intent->operationId())->projectionState(), 'Conflicting binding blocks projection.');
}

function refund_test_verified_get_rejects_changed_scope_money_and_recipient()
{
    foreach (array('amount' => '999', 'first_sender' => 'TSecondSender', 'network' => 'Ethereum', 'currency' => 'USDC',
        'mode' => 'full', 'project_id' => 'prj_foreign', 'invoice_id' => 'inv_foreign', 'external_order_id' => 'foreign', 'is_test' => false) as $field => $value) {
        $adapter = refund_setup($api, $store);
        $intent = $adapter->request('100', 'overpayment', 'token_over_pinned_01', array());
        $api->replaceGet = array($field => $value, 'status' => 'completed');
        $adapter->reconcile($intent->operationId());
        assertSameValue('native_review', $store->getIntent($intent->operationId())->projectionState(), 'GET mismatch reviews: ' . $field);
        assertSameValue(0, count($GLOBALS['refund_comments']), 'No cash projection on mismatched ' . $field);
    }
}

function refund_test_ton_raw_pin_accepts_friendly_same_account_and_rejects_another()
{
    require_once __DIR__ . '/CmsTonPinContract.php';
    refund_setup($api, $store);
    paymos_cms_ton_pin_contract($store, 'woocommerce', 'fixture-installation', '100');
}

function refund_test_crash_after_native_commit_recovers_marker_without_restock_or_document_replay()
{
    $adapter = refund_setup($api, $store);
    wc_get_order(100)->items = array(10 => new RefundItemFixture());
    $input = array_replace(refund_full_input(), array('restock_refunded_items' => 'true', 'line_item_qtys' => '{"10":2}', 'line_item_totals' => '{"10":"100"}'));
    $intent = $adapter->request('100', 'full', 'token_full_crash_01', $input);
    $api->setStatus('completed');
    $GLOBALS['wpdb']->loseProjectionCommit = true;
    $adapter->reconcile($intent->operationId());
    assertSameValue('native_review', $store->getIntent($intent->operationId())->projectionState(), 'Lost commit acknowledgement is review.');
    $adapter->reconcile($intent->operationId());
    assertSameValue('applied', $store->getIntent($intent->operationId())->projectionState(), 'Exact native marker recovers committed state.');
    assertSameValue(1, $GLOBALS['refund_native_writes'], 'Recovery never writes a second document.');
    assertSameValue(10, $GLOBALS['refund_stock'], 'Restock occurs once.');
    $api->setStatus('signed');
    $adapter->reconcile($intent->operationId());
    assertSameValue('native_review', $store->getIntent($intent->operationId())->projectionState(), 'Cash rewind retains review.');
    $api->setStatus('completed');
    $adapter->reconcile($intent->operationId());
    assertSameValue(1, $GLOBALS['refund_native_writes'], 'Recompletion reuses native document.');
    assertSameValue(10, $GLOBALS['refund_stock'], 'Recompletion never repeats stock effect.');
}

function refund_test_native_write_exception_rolls_back_document_and_stock()
{
    $adapter = refund_setup($api, $store);
    $intent = $adapter->request('100', 'full', 'token_full_rollback_01', refund_full_input());
    $api->setStatus('completed');
    $GLOBALS['refund_fail_native'] = true;
    $adapter->reconcile($intent->operationId());
    assertSameValue(0, count(wc_get_order(100)->get_refunds()), 'Exception rolls back native write.');
    assertSameValue('0', wc_get_order(100)->get_total_refunded(), 'Financial total rolled back.');
    assertSameValue('native_review', $store->getIntent($intent->operationId())->projectionState(), 'Unknown native result remains review.');
    $GLOBALS['refund_fail_native'] = false;
    $adapter->reconcile($intent->operationId());
    assertSameValue(1, count(wc_get_order(100)->get_refunds()), 'Safe absent marker recovers rollback.');
}

function refund_test_native_mutation_before_completion_requires_review()
{
    $adapter = refund_setup($api, $store);
    $intent = $adapter->request('100', 'full', 'token_full_changed_01', refund_full_input());
    wc_get_order(100)->refunded = '10';
    $api->setStatus('completed');
    $adapter->reconcile($intent->operationId());
    assertSameValue('native_review', $store->getIntent($intent->operationId())->projectionState(), 'Manual accounting change cannot be guessed.');
    assertSameValue(0, $GLOBALS['refund_native_writes'], 'No duplicate or partial financial projection.');
}

function refund_test_lost_order_lock_after_post_blocks_binding_and_native_mutations()
{
    $adapter = refund_setup($api, $store);
    $api->loseLockOnPost = true;
    $failed = false;
    try { $adapter->request('100', 'full', 'token_full_lostlock_01', refund_full_input()); } catch (RuntimeException $error) { $failed = true; }
    assertSameValue(true, $failed, 'Lost connection ownership must stop mutation after HTTP.');
    $saved = reset($GLOBALS['wpdb']->intents);
    assertSameValue(null, $saved['withdrawal_id'], 'No binding after lost mutex.');
    assertSameValue(0, $GLOBALS['refund_native_writes'], 'No native effect after lost mutex.');
}

function refund_test_effect_claim_commits_started_and_forbids_automatic_resend()
{
    $adapter = refund_setup($api, $store);
    $intent = $adapter->request('100', 'overpayment', 'token_over_effect_01', array());
    $store->withPaymentLock('100', static function () use ($store, $intent) {
        assertSameValue(EffectClaim::CLAIMED, $store->claimEffect($intent->operationId(), 'mail', 1)->kind(), 'First send attempt claimed.');
        assertSameValue(false, $GLOBALS['wpdb']->inTransaction, 'Started claim committed before caller sends.');
        assertSameValue(EffectClaim::ALREADY_STARTED, $store->claimEffect($intent->operationId(), 'mail', 1)->kind(), 'No automatic resend after crash.');
        $store->recordEffectOutcome($intent->operationId(), 'mail', 1, RefundStates::EFFECT_UNKNOWN);
        assertSameValue(EffectClaim::ALREADY_STARTED, $store->claimEffect($intent->operationId(), 'mail', 1)->kind(), 'Unknown remains at-most-once.');
        assertSameValue(EffectClaim::CLAIMED, $store->claimEffect($intent->operationId(), 'mail', 2)->kind(), 'Explicit new generation is separate from cash.');
    });
    assertSameValue(1, count($api->posts()), 'Effect generations do not create provider refunds.');
}

function refund_test_queued_native_mail_is_suppressed_only_for_marked_refund()
{
    $adapter = refund_setup($api);
    $api->status = 'completed';
    $intent = $adapter->request('100', 'full', 'token_full_email_01', refund_full_input());
    $refund = wc_get_order(100)->get_refunds()[0];
    assertSameValue(false, RefundAdapter::emailEnabled(true, wc_get_order(100), (object) array('refund' => $refund)), 'Queued Paymos mail suppressed outside module effect.');
    $unrelated = new RefundDocumentFixture(9999, 100, '5', 'USD');
    assertSameValue(true, RefundAdapter::emailEnabled(true, wc_get_order(100), (object) array('refund' => $unrelated)), 'Other gateway refund email remains enabled.');
    assertSameValue(true, RefundAdapter::emailEnabled(true, wc_get_order(100), (object) array()), 'Other email remains enabled.');
    assertSameValue('skipped', $intent->toArray()['effects']['customer_refund:1'], 'Saved disabled native mail default is honored.');
}

function refund_test_due_pagination_backoff_and_historical_fairness()
{
    $adapter = refund_setup($api, $store);
    $intent = $adapter->request('100', 'overpayment', 'token_over_due_01', array());
    $store->withPaymentLock('100', static function () use ($store, $intent) { $store->defer($intent->operationId()); });
    assertSameValue(0, count($store->listDue(new DateTimeImmutable('now'), 100, null)->items()), 'Backoff skips immediate poll.');
    $GLOBALS['wpdb']->intents[$intent->operationId()]['next_check'] = 0;
    $page = $store->listDue(new DateTimeImmutable('now'), 1, null);
    assertSameValue($intent->operationId(), $page->items()[0]->operationId(), 'Due intent appears.');
    assertSameValue($intent->operationId(), $page->nextCursor(), 'Cursor visits bounded batches.');
    assertSameValue(0, count($store->listDue(new DateTimeImmutable('now'), 1, $page->nextCursor())->items()), 'Page cursor does not repeat row.');
    $api->setStatus('completed');
    $adapter->reconcile($intent->operationId());
    $store->withPaymentLock('100', static function () use ($store, $intent) { $store->defer($intent->operationId()); });
    $row = $GLOBALS['wpdb']->intents[$intent->operationId()];
    assertTrueValue($row['next_check'] >= time() + 86399, 'Historical completed sweep is scheduled daily.');
}

function refund_test_standalone_gateway_entry_refuses_without_mutating_order()
{
    refund_setup($api);
    assertTrueValue((new Gateway())->process_refund(100, 100, '') instanceof WP_Error, 'Direct gateway call cannot invent durable context.');
    assertSameValue(0, count($api->posts()), 'No provider POST from direct gateway call.');
    assertSameValue(0, count(wc_get_order(100)->notes), 'No misleading manual refund note.');
}

function refund_test_decimal_minor_conversion_uses_strings_without_rounding()
{
    assertSameValue('9007199254740993123', RefundAdapter::minor('9007199254740993.123', 3), 'Precision beyond IEEE double survives.');
    assertSameValue('2500', RefundAdapter::minor('2500', 0), 'Zero-decimal currency.');
    refund_assert_rejected(static function () { RefundAdapter::minor('1.001', 2); }, 'refund_native_amount_invalid');
    refund_assert_rejected(static function () { RefundAdapter::minor('0', 2); }, 'refund_native_amount_invalid');
    refund_assert_rejected(static function () { RefundAdapter::minor('-1', 2); }, 'refund_native_amount_invalid');
}

function refund_callback_event(RefundIntent $intent, array $changes = array()): array
{
    return array('event_id' => 'evt_refund_test', 'event_type' => 'withdrawal.completed', 'version' => 1, 'occurred_at' => time(),
        'data' => array_replace(array('withdrawal_id' => $intent->withdrawalId() ?: 'wd_untrusted', 'invoice_id' => $intent->invoiceId(),
            'is_test' => true, 'external_order_id' => $intent->externalOrderId(),
            'mode' => $intent->mode(), 'status' => 'completed', 'amount' => '999999'), $changes));
}

function refund_callback_credentials()
{
    paymos_store_credentials(array('sandbox' => array('api_key' => 'pk_test_key', 'api_secret' => 'sk_test_secret',
        'project_id' => 'prj_123', 'webhook_secret' => 'whsec_test', 'base_url' => 'https://api.paymos.io')));
}

function refund_test_scoped_callback_uses_get_and_ignores_callback_money_and_status()
{
    $adapter = refund_setup($api, $store);
    refund_callback_credentials();
    $intent = $adapter->request('100', 'overpayment', 'token_over_callback_01', array());
    $request = new RefundCallbackRequestFixture(refund_callback_event($intent));
    assertSameValue(200, $adapter->handleCallback($request)->get_status(), 'Signed matching scope accepted.');
    assertSameValue(0, count($GLOBALS['refund_comments']), 'Callback completed does not override authoritative created GET.');
    assertSameValue(200, $adapter->handleCallback($request)->get_status(), 'Committed event deduplicates.');
    $api->setStatus('completed');
    $event = refund_callback_event($intent);
    $event['event_id'] = 'evt_refund_completed';
    assertSameValue(200, $adapter->handleCallback(new RefundCallbackRequestFixture($event))->get_status(), 'Completed fresh GET projects note.');
    assertSameValue('20', $store->getIntent($intent->operationId())->observation()->principalDecimal(), 'Callback amount never becomes evidence.');
    assertSameValue(1, count($GLOBALS['refund_comments']), 'Verified overpayment creates one note.');
}

function refund_test_callback_scope_signature_and_environment_fail_closed()
{
    foreach (array('invoice_id' => 'inv_other', 'project_id' => 'prj_other', 'is_test' => false,
        'mode' => 'full', 'withdrawal_id' => 'wd_other') as $field => $value) {
        $adapter = refund_setup($api);
        refund_callback_credentials();
        $intent = $adapter->request('100', 'overpayment', 'token_over_scope_01', array());
        $before = count($api->requests);
        $request = new RefundCallbackRequestFixture(refund_callback_event($intent, array($field => $value)));
        assertSameValue(409, $adapter->handleCallback($request)->get_status(), 'Wrong callback scope is retryable: ' . $field);
        assertSameValue($before, count($api->requests), 'Scope rejected before scoped read.');
        assertSameValue(0, count($GLOBALS['refund_comments']), 'No native projection on wrong callback scope.');
    }
    $request = new RefundCallbackRequestFixture(refund_callback_event($intent), 'whsec_wrong');
    assertSameValue(401, $adapter->handleCallback($request)->get_status(), 'Wrong signature rejected.');
}

function refund_test_callback_before_binding_replays_independent_key_instead_of_adopting_event_id()
{
    $adapter = refund_setup($api, $store, $config);
    refund_callback_credentials();
    $api->timeout = true;
    $intent = $adapter->request('100', 'overpayment', 'token_over_earlycb_01', array());
    assertSameValue(null, $intent->withdrawalId(), 'Response loss leaves intent unbound.');
    $api->timeout = false;
    $api->setStatus('completed');
    $config['refunds_enabled'] = false;
    $request = new RefundCallbackRequestFixture(refund_callback_event($intent));
    assertSameValue(200, $adapter->handleCallback($request)->get_status(), 'Independent replay recovers binding.');
    assertSameValue('wd_1', $store->getIntent($intent->operationId())->withdrawalId(), 'Never binds callback wd_untrusted.');
    assertSameValue(1, count($api->refunds), 'Recovered one server Withdrawal.');
    assertSameValue(1, count($GLOBALS['refund_comments']), 'Verified GET projects recovered overpayment.');
}

function refund_test_cash_failed_or_cancelled_allows_only_a_new_explicit_token()
{
    foreach (array('failed', 'cancelled') as $state) {
        $adapter = refund_setup($api, $store);
        $api->status = $state;
        $old = $adapter->request('100', 'full', 'token_full_terminal_01', refund_full_input());
        $again = $adapter->request('100', 'full', 'token_full_terminal_01', refund_full_input());
        assertSameValue($old->operationId(), $again->operationId(), 'Same token preserves terminal intent.');
        assertSameValue(1, count($api->posts()), 'Terminal intent never automatically resubmits.');
        $api->status = 'created';
        $new = $adapter->request('100', 'full', 'token_full_terminal_02', refund_full_input());
        assertTrueValue($new->operationId() !== $old->operationId(), 'Explicit new token creates new immutable intent.');
        assertSameValue($state, $store->getIntent($old->operationId())->cashState(), 'Old terminal history unchanged.');
        assertSameValue(0, $GLOBALS['refund_native_writes'], 'No native refund on cash failure/cancellation/pending.');
    }
}

function refund_test_native_mail_sent_once_and_unknown_is_never_automatically_resent()
{
    foreach (array(true, false, null, 'crash') as $outcome) {
        $adapter = refund_setup($api, $store);
        paymos_set_option('woocommerce_customer_refunded_order_settings', array('enabled' => 'yes'));
        $GLOBALS['refund_mailer']->outcome = $outcome;
        $api->status = 'completed';
        $intent = $adapter->request('100', 'full', 'token_full_mail_once_01', refund_full_input());
        $adapter->reconcile($intent->operationId());
        assertSameValue(1, $GLOBALS['refund_mailer']->calls, 'One external send attempt even on lost acknowledgement.');
        $state = $store->getIntent($intent->operationId());
        assertSameValue($outcome === true ? 'delivered' : 'unknown', $state->toArray()['effects']['customer_refund:1'], 'Delivery only claimed with native send acknowledgement.');
        if ($outcome !== true) { assertSameValue('native_review', $state->projectionState(), 'Unknown mail outcome is visible review.'); }
        assertSameValue(1, count($api->posts()), 'Mail never creates a new cash request.');
        assertSameValue(1, $GLOBALS['refund_native_writes'], 'Mail retry never repeats financial projection.');
    }
}

function refund_test_lost_native_marker_blocks_second_financial_write()
{
    $adapter = refund_setup($api, $store);
    $api->status = 'completed';
    $intent = $adapter->request('100', 'full', 'token_full_missing_01', refund_full_input());
    wc_get_order(100)->refunds = array();
    wc_get_order(100)->refunded = '0';
    wc_get_order(100)->paid = true;
    $adapter->reconcile($intent->operationId());
    assertSameValue('native_review', $store->getIntent($intent->operationId())->projectionState(), 'Previously persisted projection cannot disappear silently.');
    assertSameValue(1, $GLOBALS['refund_native_writes'], 'Missing marker never authorizes a second document.');
}

function refund_test_shared_payment_mutex_rejects_concurrent_action_before_provider_http()
{
    $adapter = refund_setup($api);
    $lock = new \Paymos\Plugin\WordPressOrderLock('woocommerce', 100);
    assertSameValue(true, $lock->acquire(), 'First native payment owner acquires shared mutex.');
    try {
        refund_assert_rejected(static function () use ($adapter) {
            $adapter->request('100', 'full', 'token_full_concurrent_01', refund_full_input());
        }, 'refund_payment_busy');
        assertSameValue(0, count($api->requests), 'Busy payment does not reach provider HTTP.');
    } finally { $lock->release(); }
    $adapter->request('100', 'full', 'token_full_concurrent_01', refund_full_input());
    assertSameValue(1, count($api->posts()), 'Payment recovers after mutex release.');
}

function refund_test_same_token_payload_is_immutable_in_store_and_entry()
{
    $adapter = refund_setup($api, $store);
    $intent = $adapter->request('100', 'full', 'token_full_immutable_01', refund_full_input());
    $store->withPaymentLock('100', static function () use ($store, $intent) {
        $data = $intent->toArray();
        unset($data['external_order_id'], $data['payload_hash']);
        $data['operation_id'] = wp_generate_uuid4();
        $same = new RefundIntent($data);
        assertSameValue($intent->operationId(), $store->createIntent($same)->operationId(), 'Same token and immutable values returns existing operation.');
        $data['full_draft']['reason'] = 'changed merchant reason';
        refund_assert_rejected(static function () use ($store, $data) { $store->createIntent(new RefundIntent($data)); }, 'refund_request_conflict');
    });
    $input = array_replace(refund_full_input(), array('refund_reason' => 'changed merchant reason'));
    refund_assert_rejected(static function () use ($adapter, $input) { $adapter->request('100', 'full', 'token_full_immutable_01', $input); }, 'refund_full_native_input_required');
    assertSameValue(1, count($api->posts()), 'Changed saved draft never sends another cash POST.');
}

function refund_test_summary_disables_both_actions_for_unresolved_and_full_completed()
{
    $adapter = refund_setup($api, $store, $config);
    assertSameValue(true, $adapter->summary('100')['can_refund_overpayment'], 'Authoritative extra amount is initially available.');
    $intent = $adapter->request('100', 'full', 'token_full_summary_01', refund_full_input());
    $summary = $adapter->summary('100');
    assertSameValue(false, $summary['can_refund_full'], 'Unresolved intent disables full action.');
    assertSameValue(false, $summary['can_refund_overpayment'], 'Unresolved intent also disables overpayment action.');
    $api->setStatus('completed');
    $adapter->reconcile($intent->operationId());
    $summary = $adapter->summary('100');
    assertSameValue(false, $summary['can_refund_full'], 'Completed full disables full.');
    assertSameValue(false, $summary['can_refund_overpayment'], 'Completed full disables overpayment.');
    $posts = count($api->posts());
    refund_assert_rejected(static function () use ($adapter) { $adapter->request('100', 'overpayment', 'token_over_afterfull_01', array()); }, 'refund_original_full_paid_required');
    assertSameValue($posts, count($api->posts()), 'No cash request after native full completion.');
}

function refund_test_unresolved_over_24h_is_review_without_losing_unknown_key_recovery()
{
    $adapter = refund_setup($api, $store);
    $api->timeout = true;
    $intent = $adapter->request('100', 'overpayment', 'token_over_aged_01', array());
    $row = &$GLOBALS['wpdb']->intents[$intent->operationId()];
    $data = json_decode($row['payload'], true);
    $data['created_at'] = time() - 86401;
    $row['payload'] = json_encode($data);
    unset($row);
    $store->withPaymentLock('100', static function () use ($store, $intent) { $store->defer($intent->operationId()); });
    $aged = $store->getIntent($intent->operationId());
    assertSameValue('unknown', $aged->cashState(), 'Age does not convert unknown to definitive failed.');
    assertSameValue('native_review', $aged->projectionState(), 'Unresolved cash over 24 hours needs review.');
    $api->timeout = false;
    $adapter->reconcile($intent->operationId());
    assertSameValue('wd_1', $store->getIntent($intent->operationId())->withdrawalId(), 'Same-key recovery still learns the existing Withdrawal.');
    assertSameValue(1, count($api->refunds), 'No extra cash operation after age threshold.');
}

function refund_test_unbound_and_ordinary_withdrawal_callbacks_never_initiate_money()
{
    $adapter = refund_setup($api);
    refund_callback_credentials();
    $intent = $adapter->request('100', 'overpayment', 'token_over_orphan_01', array());
    $before = count($api->posts());
    $unbound = refund_callback_event($intent, array('external_order_id' => 'pmrf:unknown'));
    assertSameValue(409, $adapter->handleCallback(new RefundCallbackRequestFixture($unbound))->get_status(), 'Unbound cash needs review; callback is never adopted as a new refund intent.');
    $ordinary = refund_callback_event($intent);
    $ordinary['event_id'] = 'evt_ordinary';
    unset($ordinary['data']['invoice_id']);
    assertSameValue(200, $adapter->handleCallback(new RefundCallbackRequestFixture($ordinary))->get_status(), 'Ordinary merchant withdrawal is ignored.');
    assertSameValue($before, count($api->posts()), 'Unbound/ordinary events do not initiate money.');
}

function refund_test_cached_disabled_handshake_uses_authoritative_server_eligibility()
{
    $adapter = refund_setup($api, $store, $config);
    $config['refunds_enabled'] = false;
    assertSameValue(true, $adapter->summary('100')['can_refund_overpayment'], 'The server can enable refunds after connection without reconnect.');
    $intent = $adapter->request('100', 'overpayment', 'fresh-server-gate-001', array());
    assertSameValue('created', $intent->cashState(), 'Fresh server eligibility permits one new refund.');
    assertSameValue(1, count($api->posts()), 'One cash POST was made.');
    $adapter = refund_setup($api, $store, $config);
    $api->invoice['refund_summary']['can_refund_overpayment'] = false;
    $disabled = $adapter->request('100', 'overpayment', 'server-disabled-0001', array());
    assertSameValue('review', $disabled->cashState(), 'Fresh unavailable action remains a local review without starting cash.');
    assertSameValue('refund_server_not_eligible', $disabled->toArray()['review_reason'], 'The current server gate is the reason.');
    assertSameValue(0, count($api->posts()), 'A fresh disabled server summary prevents a new cash POST.');
}

function refund_external_wire(array $changes = array()): array
{
    return array_replace(array('type' => 'refund', 'withdrawal_id' => 'wd_external', 'invoice_id' => 'inv_123', 'project_id' => 'prj_123',
        'is_test' => true, 'mode' => 'overpayment', 'external_order_id' => 'dashboard-key', 'status' => 'completed',
        'amount' => '20', 'currency' => 'USDT', 'network' => 'Tron', 'first_sender' => 'TFirstSender'), $changes);
}

function refund_test_external_callback_records_cash_without_a_local_intent_or_provider_post()
{
    $adapter = refund_setup($api, $store);
    refund_callback_credentials();
    $wire = refund_external_wire();
    $api->refunds['dashboard-key'] = $wire;
    $event = array('event_id' => 'evt_external', 'event_type' => 'withdrawal.completed', 'version' => 1, 'occurred_at' => time(), 'data' => $wire);
    $request = new RefundCallbackRequestFixture($event);
    assertSameValue(200, $adapter->handleCallback($request)->get_status(), 'A verified dashboard refund is observed without a CMS intent.');
    assertSameValue(200, $adapter->handleCallback($request)->get_status(), 'Duplicate signed callback is idempotent.');
    assertSameValue(1, count($GLOBALS['refund_comments']), 'Exactly one cash-only note.');
    assertSameValue(array(), $GLOBALS['wpdb']->intents, 'No synthetic refund intent is invented.');
    assertSameValue(0, count($api->posts()), 'Callback processing cannot send cash.');
    assertSameValue('0', wc_get_order(100)->get_total_refunded(), 'External overpayment leaves native refunded total unchanged.');
    assertSameValue(8, $GLOBALS['refund_stock'], 'No stock effects.');
    $api->refunds['dashboard-key']['status'] = 'signed';
    $adapter->observeExternal($wire, 'sandbox');
    $rows = wc_get_order(100)->get_meta('_paymos_external_refunds', true);
    assertSameValue('cash_rewound', $rows['wd_external']['review_reason'], 'Fresh reorg read changes the cash state without changing the note.');
    $api->refunds['dashboard-key']['status'] = 'completed';
    $adapter->observeExternal($wire, 'sandbox');
    assertSameValue(1, count($GLOBALS['refund_comments']), 'Recompletion never duplicates a native note.');
}

function refund_test_external_discovery_recovers_a_missing_callback_and_full_requires_review()
{
    $adapter = refund_setup($api, $store, $config);
    $wire = refund_external_wire(array('mode' => 'full', 'amount' => '120'));
    $api->refunds['dashboard-key'] = $wire;
    $client = new Client(new ClientConfig('pk_test_key', 'sk_test_secret', 'https://api.paymos.test'), $api);
    $adapter = new RefundAdapter($store, static function () use ($client) { return $client; },
        static function ($environment) use ($config) { return $environment === 'sandbox' ? $config : array(); });
    assertSameValue(1, $adapter->discoverExternal(), 'The scoped list discovers cash even when no callback or local intent exists.');
    $rows = wc_get_order(100)->get_meta('_paymos_external_refunds', true);
    assertSameValue('refund_unbound_full_draft_missing', $rows['wd_external']['review_reason'], 'Native full draft cannot be guessed from external cash.');
    assertSameValue(false, $adapter->summary('100')['can_refund_full'], 'A full observation needing review blocks new native actions.');
    assertSameValue(0, count($GLOBALS['refund_comments']), 'Full observation does not make an overpayment note or financial refund.');
    assertSameValue(0, count($api->posts()), 'Discovery performs only signed reads.');
    $api->refunds['dashboard-key']['project_id'] = 'prj_foreign';
    try { $adapter->observeExternal($wire, 'sandbox'); } catch (\UnexpectedValueException $expected) { return; }
    throw new RuntimeException('Changed project must fail before a native write.');
}

$refundCount = 0;
foreach (get_defined_functions()['user'] as $function) {
    if (strpos($function, 'refund_test_') !== 0) { continue; }
    $function();
    $refundCount++;
    echo "PASS {$function} (stub service oracle)\n";
}
echo "OK {$refundCount} refund service tests; native WordPress/WooCommerce not exercised\n";
