<?php

declare(strict_types=1);

use PaymosWooCommerce\OrderMapper;
use PaymosWooCommerce\OrderAmountGuard;
use Paymos\Plugin\WordPressOrderLock;

function publicationPaidEvent()
{
    return array('event_id' => 'evt_retry', 'event_type' => 'invoice.paid', 'data' => array('invoice_id' => 'inv_123', 'status' => 'paid', 'order' => array('amount' => '100', 'currency' => 'USD')));
}

function test_paid_mismatch_can_recover_without_double_fulfillment()
{
    paymos_reset_test_state();
    $order = new FakeOrder('110', 'USD');
    OrderAmountGuard::capture($order, '100', 'USD');
    $mapper = new OrderMapper();
    $mapper->apply($order, publicationPaidEvent());
    assertSameValue(false, $order->paid, 'Mismatched order stays unpaid.');
    assertSameValue('', $order->get_meta('_paymos_last_status'), 'Observation must not become applied paid marker.');
    $order->set_total('100');
    $mapper->apply($order, publicationPaidEvent());
    $mapper->apply($order, publicationPaidEvent());
    assertSameValue(true, $order->paid, 'Corrected order recovers on retry.');
    assertSameValue(1, $order->completionCalls, 'Duplicate paid event does not fulfill twice.');
}

function test_native_completion_false_remains_retryable()
{
    paymos_reset_test_state();
    $order = new FakeOrder();
    OrderAmountGuard::capture($order, '100', 'USD');
    $order->completionResult = false;
    $failed = false;
    try { (new OrderMapper())->apply($order, publicationPaidEvent()); } catch (RuntimeException $e) { $failed = true; }
    assertSameValue(true, $failed, 'Native false is not successful payment application.');
    assertSameValue('', $order->get_meta('_paymos_last_status'), 'Native false cannot persist applied final.');
    $order->completionResult = true;
    (new OrderMapper())->apply($order, publicationPaidEvent());
    assertSameValue(true, $order->paid, 'Native failure can recover on retry.');
}

function test_decimal_precision_and_missing_snapshots_fail_closed()
{
    paymos_reset_test_state();
    $order = new FakeOrder('33.104', 'KWD');
    OrderAmountGuard::capture($order, '33.104', 'KWD');
    assertSameValue('33.104', $order->get_meta('_paymos_invoice_amount'), 'KWD third digit survives snapshot.');
    $event = array('data' => array('order' => array('amount' => '33.10', 'currency' => 'KWD')));
    assertSameValue(false, OrderAmountGuard::isSafeToComplete($order, $event), 'Insufficient 33.10 cannot complete 33.104.');
    $event['data']['order']['amount'] = '33.104';
    assertSameValue(true, OrderAmountGuard::isSafeToComplete($order, $event), 'Exact third decimal is valid.');
    $order->meta = array();
    assertSameValue(false, OrderAmountGuard::isSafeToComplete($order, $event), 'Legacy missing snapshot requires manual review.');
}

function test_fresh_order_prevents_stale_confirming_rollback_and_preserves_refund()
{
    paymos_reset_test_state();
    $stale = new FakeOrder();
    $fresh = new FakeOrder();
    $fresh->paid = true;
    (new OrderMapper())->apply($stale, array('event_type' => 'invoice.confirming', 'data' => array('status' => 'confirming')));
    assertSameValue(0, count($fresh->statusUpdates), 'Mapper reload sees completion performed after caller loaded stale object.');
    assertSameValue(0, count($stale->statusUpdates), 'Detached stale object is never written.');
    $fresh->paid = false;
    $fresh->update_status('refunded', 'manual refund');
    (new OrderMapper())->apply($fresh, publicationPaidEvent());
    assertSameValue(false, $fresh->paid, 'Refunded native order cannot be reopened as paid.');
}

function test_order_mutex_busy_does_not_mutate_and_retries()
{
    paymos_reset_test_state();
    $order = new FakeOrder();
    $lock = new WordPressOrderLock('woocommerce', $order->get_id());
    assertSameValue(true, $lock->acquire(), 'Competing checkout owns order.');
    $failed = false;
    try { (new OrderMapper())->apply($order, publicationPaidEvent()); } catch (RuntimeException $e) { $failed = true; }
    assertSameValue(true, $failed, 'Same order different event cannot run concurrently.');
    assertSameValue(false, $order->paid, 'Busy order remains unchanged.');
    $lock->release();
    OrderAmountGuard::capture($order, '100', 'USD');
    (new OrderMapper())->apply($order, publicationPaidEvent());
    assertSameValue(true, $order->paid, 'Retry after release succeeds.');
}

function is_multisite() { return !empty($GLOBALS['paymos_multisite']); }
function get_sites($args) { return array(1, 2); }
function switch_to_blog($id)
{
    global $wpdb;
    $previous = $GLOBALS['paymos_current_blog'] ?? 0;
    $GLOBALS['paymos_blog_stack'][] = $previous;
    $GLOBALS['paymos_blog_options'][$previous] = $GLOBALS['paymos_test_options'];
    $GLOBALS['paymos_current_blog'] = $id;
    $GLOBALS['paymos_test_options'] = $GLOBALS['paymos_blog_options'][$id] ?? array();
    $wpdb->prefix = $id === 1 ? 'wp_' : 'wp_' . $id . '_';
    $wpdb->options = $wpdb->prefix . 'options';
}
function restore_current_blog()
{
    global $wpdb;
    $current = $GLOBALS['paymos_current_blog'];
    $GLOBALS['paymos_blog_options'][$current] = $GLOBALS['paymos_test_options'];
    $previous = array_pop($GLOBALS['paymos_blog_stack']);
    $GLOBALS['paymos_current_blog'] = $previous;
    $GLOBALS['paymos_test_options'] = $GLOBALS['paymos_blog_options'][$previous];
    $wpdb->prefix = $previous <= 1 ? 'wp_' : 'wp_' . $previous . '_';
    $wpdb->options = $wpdb->prefix . 'options';
}

function test_uninstall_cleans_only_owned_runtime_state_for_each_blog()
{
    global $wpdb;
    paymos_reset_test_state();
    if (!defined('WP_UNINSTALL_PLUGIN')) { define('WP_UNINSTALL_PLUGIN', true); }
    $seed = array('paymos_woocommerce_credentials_v1' => 'secret', 'paymos_woocommerce_connect_state_v1' => 'device', 'paymos_woocommerce_event_schema' => '2', 'paymos_woocommerce_reconcile_waiting_cursor' => 125, 'paymos_edd_credentials_v1' => 'other adapter', 'order_history' => 'preserved');
    $GLOBALS['paymos_multisite'] = true;
    $GLOBALS['paymos_blog_options'] = array(1 => $seed, 2 => $seed);
    $GLOBALS['paymos_current_blog'] = 0;
    require dirname(__DIR__) . '/uninstall.php';
    foreach (array(1, 2) as $id) {
        assertSameValue(array('paymos_edd_credentials_v1' => 'other adapter', 'order_history' => 'preserved'), $GLOBALS['paymos_blog_options'][$id], 'Uninstall clears owned state and preserves other adapter/history.');
    }
    $drops = array_values(array_filter($wpdb->queries, static function ($q) { return is_array($q) && strpos($q['query'], 'DROP TABLE') === 0; }));
    assertSameValue(array('wp_paymos_webhook_events', 'wp_2_paymos_webhook_events'), array_map(static function ($q) { return $q['args'][0]; }, $drops), 'Only each blog scoped runtime table is dropped.');
    assertSameValue(0, $GLOBALS['paymos_current_blog'], 'Original blog restored.');
    require dirname(__DIR__) . '/uninstall.php';
    $GLOBALS['paymos_multisite'] = false;
    unset($GLOBALS['paymos_blog_options'], $GLOBALS['paymos_current_blog'], $GLOBALS['paymos_blog_stack']);
}

function test_checkout_urls_are_validated_on_both_new_and_resumed_paths()
{
    foreach (array('https://checkout.paymos.io/i/123', 'https://checkout-local.paymos.io/i/123', 'https://checkout.paymos.io:443/i/123') as $url) {
        assertSameValue(true, \PaymosWooCommerce\CheckoutUrl::isTrusted($url), 'Known HTTPS checkout origin accepted.');
    }
    foreach (array('http://checkout.paymos.io/i/123', 'https://checkout.paymos.io.attacker.test/', 'https://attacker.test/', 'https://user@checkout.paymos.io/', 'https://checkout.paymos.io:444/', '//checkout.paymos.io/') as $url) {
        assertSameValue(false, \PaymosWooCommerce\CheckoutUrl::isTrusted($url), 'Untrusted checkout origin rejected.');
    }
    $order = paymos_renewal_order_with_invoice('awaiting_client');
    $order->meta['_paymos_payment_url'] = 'https://attacker.test/';
    $actions = \PaymosWooCommerce\StorefrontHooks::my_orders_actions(array('pay' => array('url' => '/native-pay')), $order);
    assertSameValue(false, isset($actions['paymos_pay']), 'Stored untrusted URL never replaces native pay action.');
}

function publicationWebhookFixture()
{
    paymos_reset_test_state();
    paymos_store_credentials(array('sandbox' => array('api_key' => 'pk_test_key', 'api_secret' => 'sk_test_secret', 'project_id' => 'prj_123', 'webhook_secret' => 'whsec_test', 'base_url' => 'https://api.paymos.io')));
    $order = new FakeOrder();
    OrderAmountGuard::capture($order, '100', 'USD');
    foreach (array('_paymos_invoice_id' => 'inv_123', '_paymos_external_order_id' => 'wc_100', '_paymos_environment' => 'sandbox', '_paymos_project_id' => 'prj_123') as $key => $value) { $order->update_meta_data($key, $value); }
    $data = array('invoice_id' => 'inv_123', 'project_id' => 'prj_123', 'is_test' => true, 'status' => 'paid', 'order' => array('external_id' => 'wc_100', 'amount' => '100', 'currency' => 'USD'));
    $transport = new \Paymos\Http\MockTransport(array(new \Paymos\Http\HttpResponse(200, json_encode($data), array()), new \Paymos\Http\HttpResponse(200, json_encode($data), array())));
    $client = new \Paymos\Client(new \Paymos\ClientConfig('pk_test_key', 'sk_test_secret', 'https://api.paymos.test'), $transport);
    paymos_set_webhook_client_factory(static function () use ($client) { return $client; });
    return array($order, array('event_id' => 'evt_native_retry', 'event_type' => 'invoice.paid', 'version' => 1, 'occurred_at' => time(), 'data' => $data));
}
function publicationDeliver(array $event)
{
    $body = json_encode($event);
    return \PaymosWooCommerce\WebhookController::handle(new FakeRestRequest($body, array('x-webhook-signature' => paymos_signed_header('whsec_test', $body, time()))));
}
function test_signed_webhook_native_false_is_non_ack_and_same_event_recovers()
{
    list($order, $event) = publicationWebhookFixture();
    $order->completionResult = false;
    assertSameValue(400, publicationDeliver($event)->get_status(), 'Failed native completion is not acknowledged.');
    assertSameValue(false, (new \PaymosWooCommerce\EventStore())->isCommitted($event['event_id']), 'Failed completion never commits event.');
    $order->completionResult = true;
    assertSameValue(200, publicationDeliver($event)->get_status(), 'Exact signed event retries after native failure.');
    assertSameValue(true, $order->paid, 'Retry completes order.');
    assertSameValue(true, (new \PaymosWooCommerce\EventStore())->isCommitted($event['event_id']), 'Applied retry commits.');
}
function test_signed_webhook_cannot_bind_another_invoice_to_same_external_order()
{
    list($order, $event) = publicationWebhookFixture();
    $event['data']['invoice_id'] = 'inv_unrelated';
    assertSameValue(400, publicationDeliver($event)->get_status(), 'Exact stored invoice binding is required before API call.');
    assertSameValue(false, $order->paid, 'Wrong invoice never completes order.');
}
function test_unapplied_paid_observation_does_not_accept_confirming_rollback()
{
    paymos_reset_test_state();
    $order = new FakeOrder();
    $order->update_meta_data('_paymos_last_status', 'paid');
    (new OrderMapper())->apply($order, array('event_type' => 'invoice.confirming', 'data' => array('status' => 'confirming')));
    assertSameValue('paid', $order->get_meta('_paymos_last_status'), 'Legacy paid observation is preserved while allowing only completion retry.');
    assertSameValue(0, count($order->statusUpdates), 'Stale confirming never changes native status.');
}

function test_native_nonpaid_transition_false_does_not_persist_final_and_recovers()
{
    paymos_reset_test_state();
    $order = new FakeOrder();
    $order->transitionResult = false;
    $event = array('event_type' => 'invoice.expired', 'data' => array('status' => 'expired'));
    $failed = false;
    try { (new OrderMapper())->apply($order, $event); } catch (RuntimeException $e) { $failed = true; }
    assertSameValue(true, $failed, 'Failed native cancellation cannot be acknowledged.');
    assertSameValue('', $order->get_meta('_paymos_last_status'), 'Unapplied expired never becomes persisted final guard.');
    $order->transitionResult = true;
    (new OrderMapper())->apply($order, $event);
    assertSameValue('cancelled', $order->get_status(), 'Cancellation retry succeeds.');
    assertSameValue('expired', $order->get_meta('_paymos_last_status'), 'Applied terminal status recorded after native success.');
}


final class LayeredCachedOrder extends FakeOrder
{
    public $databaseRow;
    public $rawCachedRow;
    public $rawClearCalls = 0;
    public $nativeReadCalls = 0;
    public $forcedMetaReads = 0;
    public $supportsRawCache = true;
    public $cachedMetadata = null;
    public $nativeMetaClearCalls = 0;

    public function __construct(array $databaseRow)
    {
        parent::__construct('100', 'USD');
        $this->databaseRow = $databaseRow;
        $this->rawCachedRow = array('paid' => false, 'total' => '100');
        $this->meta = array('_paymos_invoice_amount' => '100', '_paymos_invoice_currency' => 'USD', '_paymos_invoice_id' => 'inv_old_cached');
    }

    public function get_data_store()
    {
        return new class($this) {
            private $order;
            public function __construct($order) { $this->order = $order; }
            public function has_callable($method) { return $this->order->supportsRawCache && $method === 'clear_cached_data'; }
            public function clear_cached_data($ids) {
                assertSameValue(array(100), $ids, 'Only owned native order cache is invalidated.');
                if ($this->order->rawCachedRow !== null) { $this->order->cachedMetadata = null; }
                $this->order->rawCachedRow = null;
                $this->order->rawClearCalls++;
            }
            public function read($order) {
                $row = $order->rawCachedRow !== null ? $order->rawCachedRow : $order->databaseRow;
                $order->paid = $row['paid'];
                $order->set_total($row['total']);
                $order->nativeReadCalls++;
            }
        };
    }

    public function read_meta_data($force = false)
    {
        if ($force) {
            $this->forcedMetaReads++;
            $this->meta = $this->cachedMetadata !== null ? $this->cachedMetadata : $this->databaseRow['meta'];
        }
    }
}

function layeredPaidOrder()
{
    return new LayeredCachedOrder(array('paid' => true, 'total' => '100', 'meta' => array('_paymos_invoice_amount' => '100', '_paymos_invoice_currency' => 'USD', '_paymos_invoice_id' => 'inv_123', '_paymos_external_order_id' => 'wc_100', '_paymos_environment' => 'sandbox', '_paymos_project_id' => 'prj_123', '_paymos_last_status' => 'paid')));
}

function test_layered_woo_factory_and_raw_cache_cannot_roll_back_paid_order()
{
    paymos_reset_test_state();
    $cached = layeredPaidOrder();
    // The factory returns the SAME stale object; the independent DB row is already paid.
    assertSameValue($cached, wc_get_order(100), 'Factory object cache remains populated.');
    (new OrderMapper())->apply($cached, array('event_type' => 'invoice.confirming', 'data' => array('status' => 'confirming')));
    assertSameValue(true, $cached->paid, 'Raw HPOS cache must be invalidated and native state re-read after mutex.');
    assertSameValue(0, count($cached->statusUpdates), 'Stale confirming cannot roll back completed native order.');
    assertSameValue('inv_123', $cached->get_meta('_paymos_invoice_id'), 'Forced metadata refresh must bypass stale invoice cache.');
    assertSameValue(1, $cached->rawClearCalls, 'HPOS raw cache cleared before read.');
    assertSameValue(1, $cached->forcedMetaReads, 'Metadata explicitly forced from DB.');
}

function test_layered_woo_cached_unpaid_object_cannot_complete_database_paid_order_twice()
{
    paymos_reset_test_state();
    $cached = layeredPaidOrder();
    (new OrderMapper())->apply($cached, publicationPaidEvent());
    assertSameValue(0, $cached->completionCalls, 'Already-paid DB order must not call native fulfillment again from stale factory object.');
}


function test_layered_woo_checkout_reloads_before_creating_invoice()
{
    paymos_reset_test_state();
    $cached = layeredPaidOrder();
    $result = (new \PaymosWooCommerce\Gateway())->process_payment(100);
    assertSameValue('failure', $result['result'], 'Already-paid DB order must not create another invoice from stale checkout object.');
    assertSameValue(true, $cached->paid, 'Checkout reloads native paid state.');
    assertSameValue(1, $cached->rawClearCalls, 'Checkout evicts HPOS raw cache inside mutex.');
    assertSameValue(1, $cached->forcedMetaReads, 'Checkout refreshes metadata from DB.');
}

function test_layered_woo_webhook_refreshes_binding_and_avoids_duplicate_fulfillment()
{
    list($unusedOrder, $event) = publicationWebhookFixture();
    $cached = layeredPaidOrder();
    assertSameValue('inv_old_cached', $cached->get_meta('_paymos_invoice_id'), 'Factory contains stale invoice binding.');
    assertSameValue(200, publicationDeliver($event)->get_status(), 'Webhook refreshes binding from DB before validating event.');
    assertSameValue(0, $cached->completionCalls, 'Already-completed native order does not fulfill again.');
    assertSameValue(1, $cached->rawClearCalls, 'Webhook evicts HPOS raw cache.');
    assertSameValue(1, $cached->forcedMetaReads, 'Webhook refreshes metadata binding.');
}

function test_layered_woo_cron_refreshes_paid_state_before_api_or_mapper()
{
    paymos_reset_test_state();
    $cached = layeredPaidOrder();
    $apiCalls = 0;
    $count = \PaymosWooCommerce\Reconciler::reconcile_orders(array($cached), static function () use (&$apiCalls) { $apiCalls++; throw new RuntimeException('Already paid orders need no API call.'); });
    assertSameValue(0, $count, 'Cron does not count a second fulfillment.');
    assertSameValue(0, $apiCalls, 'Fresh native paid state is checked before API call.');
    assertSameValue(0, $cached->completionCalls, 'Cron never fulfills cached stale unpaid order again.');
    assertSameValue(1, $cached->rawClearCalls, 'Cron evicts HPOS raw cache.');
    assertSameValue(1, $cached->forcedMetaReads, 'Cron refreshes metadata.');
}

function test_fresh_order_forces_meta_when_raw_cache_missing_and_supports_legacy_store()
{
    foreach (array(true, false) as $rawCacheSupported) {
        paymos_reset_test_state();
        $cached = layeredPaidOrder();
        $cached->supportsRawCache = $rawCacheSupported;
        $cached->rawCachedRow = null;
        $GLOBALS['paymos_cleaned_post_cache'] = array();
        (new OrderMapper())->apply($cached, publicationPaidEvent());
        assertSameValue(true, $cached->paid, 'Native DB state is loaded even when there is no raw cached row.');
        assertSameValue('inv_123', $cached->get_meta('_paymos_invoice_id'), 'Forced metadata read must not depend on a raw cache entry existing.');
        assertSameValue($rawCacheSupported ? 1 : 0, $cached->rawClearCalls, 'Legacy data store does not need unsupported HPOS clear method.');
        assertSameValue(array(100), $GLOBALS['paymos_cleaned_post_cache'], 'WordPress post/meta cache is invalidated for CPT compatibility.');
        assertSameValue(1, $cached->nativeReadCalls, 'Native data store read is mandatory.');
        assertSameValue(1, $cached->forcedMetaReads, 'Metadata always uses forced native read.');
    }
}


// Deterministic retry after another second without sleeping or changing real clocks.
eval('namespace PaymosWooCommerce; function time() { return isset($GLOBALS["paymos_retry_clock"]) ? $GLOBALS["paymos_retry_clock"] : \time(); }');

function test_replacement_post_timeout_retry_reuses_durable_exact_intent()
{
    $order = paymos_renewal_order_with_invoice('underpaid');
    paymos_renewal_setup($order, array(), $unused);
    $accepted = array();
    $lostResponse = true;
    $GLOBALS['paymos_retry_clock'] = time();
    $GLOBALS['paymos_test_remote_handler'] = static function ($url, $args) use (&$accepted, &$lostResponse) {
        $payload = json_decode((string) $args['body'], true);
        $external = $payload['external_order_id'];
        if ($external === 'wc_100_abc123') {
            return array('response' => array('code' => 201), 'body' => json_encode(array('invoice_id' => 'inv_old', 'status' => 'underpaid', 'is_final' => true, 'payment_url' => 'https://checkout.paymos.io/inv_old')), 'headers' => array());
        }
        if (!isset($accepted[$external])) { $accepted[$external] = $payload; }
        if ($lostResponse) { return new WP_Error('timeout', 'Accepted by API; response lost.'); }
        return array('response' => array('code' => 201), 'body' => json_encode(array('invoice_id' => 'inv_recovered', 'status' => 'awaiting_client', 'payment_url' => 'https://checkout.paymos.io/inv_recovered')), 'headers' => array());
    };
    assertSameValue('failure', (new \PaymosWooCommerce\Gateway())->process_payment(100)['result'], 'Lost response reports checkout failure.');
    $lostResponse = false;
    $GLOBALS['paymos_retry_clock'] += 60;
    $result = (new \PaymosWooCommerce\Gateway())->process_payment(100);
    unset($GLOBALS['paymos_retry_clock']);
    assertSameValue('success', $result['result'], 'Retry recovers accepted invoice.');
    assertSameValue(1, count($accepted), 'A retry in another second must not create a second payable invoice.');
    $payload = reset($accepted);
    assertSameValue($payload['external_order_id'], $order->get_meta('_paymos_external_order_id'), 'Durable intent remains exactly bound.');
}


function pendingCreationFixture()
{
    list($order, $event) = publicationWebhookFixture();
    $lock = new WordPressOrderLock('woocommerce', 100);
    $lock->acquire();
    try {
        \PaymosWooCommerce\CreationIntent::persist($order, array('project_id' => 'prj_123', 'external_order_id' => 'wc_100', 'amount' => '100', 'currency' => 'USD', 'client_id' => '7'), 'sandbox', $lock);
    } finally { $lock->release(); }
    return array($order, $event);
}

function pendingCreationClient(array $data, $count = 2, &$transport = null)
{
    $responses = array();
    for ($i = 0; $i < $count; $i++) { $responses[] = new \Paymos\Http\HttpResponse(200, json_encode($data), array()); }
    $transport = new \Paymos\Http\MockTransport($responses);
    $client = new \Paymos\Client(new \Paymos\ClientConfig('pk_test_key', 'sk_test_secret', 'https://api.paymos.test'), $transport);
    paymos_set_webhook_client_factory(static function () use ($client) { return $client; });
    return $client;
}

function test_pending_creation_callback_learns_id_via_exact_post_then_get_verifies()
{
    list($order, $event) = pendingCreationFixture();
    pendingCreationClient($event['data'], 2, $transport);
    assertSameValue('', $order->get_meta('_paymos_invoice_id'), 'Lost creation response leaves no callback-derived invoice binding.');
    assertSameValue(200, publicationDeliver($event)->get_status(), 'Genuine callback recovers durable intent and completes.');
    assertSameValue(array('POST', 'GET'), array_column($transport->requests(), 'method'), 'ID is learned by independent idempotent POST and payment is GET verified.');
    assertSameValue($order->get_meta('_paymos_creation_payload'), json_decode($transport->requests()[0]['body'], true), 'Recovery POST uses exact durable payload including client ID.');
    assertSameValue('inv_123', $order->get_meta('_paymos_invoice_id'), 'Independent API invoice ID durably bound.');
    assertSameValue('bound', $order->get_meta('_paymos_creation_intent'), 'Pending clears only after bound ID saved.');
    assertSameValue(1, $order->completionCalls, 'Recovered payment fulfills once.');
}

function test_pending_callback_never_adopts_callback_invoice_id()
{
    list($order, $event) = pendingCreationFixture();
    pendingCreationClient($event['data'], 1, $transport);
    $event['data']['invoice_id'] = 'inv_callback_unrelated';
    assertSameValue(400, publicationDeliver($event)->get_status(), 'Callback ID different from independent API ID is rejected.');
    assertSameValue('inv_123', $order->get_meta('_paymos_invoice_id'), 'Only POST response ID is learned.');
    assertSameValue(false, $order->paid, 'Rejected binding never completes.');
    assertSameValue(array('POST'), array_column($transport->requests(), 'method'), 'Wrong callback cannot choose a GET target.');
}

function test_pending_creation_cron_recovers_missed_callback()
{
    list($order, $event) = pendingCreationFixture();
    $client = pendingCreationClient($event['data'], 2, $transport);
    assertSameValue(1, \PaymosWooCommerce\Reconciler::reconcile_orders(array($order), static function () use ($client) { return $client; }), 'Cron recovers pending invoice before normal GET reconciliation.');
    assertSameValue(array('POST', 'GET'), array_column($transport->requests(), 'method'), 'Cron uses independent exact POST then reverse GET.');
    assertSameValue(true, $order->paid, 'Missed callback does not orphan accepted invoice.');
}

function test_changed_pending_intent_recovers_then_blocks_unclosable_or_unclear_invoice()
{
    foreach (array('paid', 'awaiting_payment', 'underpaid_waiting', 'unknown', 'missing_id') as $status) {
        list($order, $unused) = pendingCreationFixture();
        $order->set_total('120');
        $created = array();
        $GLOBALS['paymos_test_remote_handler'] = static function ($url, $args) use (&$created, $status) {
            if ($args['method'] === 'POST' && substr($url, -7) !== '/cancel') {
                $payload = json_decode($args['body'], true);
                $created[] = $payload;
                return array('response' => array('code' => 201), 'body' => json_encode($status === 'missing_id' ? array() : array('invoice_id' => 'inv_pending_old', 'status' => $status)), 'headers' => array());
            }
            if ($args['method'] === 'POST') {
                return array('response' => array('code' => 409), 'body' => json_encode(array('status' => 409, 'code' => 'invoice_cannot_be_cancelled')), 'headers' => array());
            }
            return array('response' => array('code' => 200), 'body' => json_encode(array('invoice_id' => 'inv_pending_old', 'status' => $status)), 'headers' => array());
        };
        assertSameValue('failure', (new \PaymosWooCommerce\Gateway())->process_payment(100)['result'], 'Changed pending intent cannot silently replace ' . $status . '.');
        assertSameValue(1, count($created), 'Only exact old intent may be replayed; no new payable invoice POST.');
        assertSameValue('wc_100', $created[0]['external_order_id'], 'Old exact external identity recovered first.');
        assertSameValue('100', $created[0]['amount'], 'Recovery uses original amount despite edited order.');
        assertSameValue('wc_100', $order->get_meta('_paymos_external_order_id'), 'Blocked replacement retains old invoice binding.');
    }
}

function test_changed_pending_intent_closes_recovered_invoice_before_new_post()
{
    list($order, $unused) = pendingCreationFixture();
    $order->set_total('120');
    $calls = array();
    $GLOBALS['paymos_test_remote_handler'] = static function ($url, $args) use (&$calls, $order) {
        if (substr($url, -7) === '/cancel') {
            $calls[] = 'cancel-old';
            $invoice = array('invoice_id' => 'inv_pending_old', 'status' => 'cancelled');
        } else {
            $payload = json_decode($args['body'], true);
            if ($payload['external_order_id'] === 'wc_100') {
                $calls[] = 'recover-old';
                $invoice = array('invoice_id' => 'inv_pending_old', 'status' => 'awaiting_client');
            } else {
                $calls[] = 'create-new';
                assertSameValue('pending', $order->get_meta('_paymos_creation_intent'), 'New intent is durable before POST.');
                assertSameValue($payload, $order->get_meta('_paymos_creation_payload'), 'Persisted payload exactly matches new request.');
                assertSameValue('', $order->get_meta('_paymos_invoice_id'), 'No old invoice ID can bind the new intent.');
                $invoice = array('invoice_id' => 'inv_new', 'status' => 'awaiting_client', 'payment_url' => 'https://checkout.paymos.io/inv_new');
            }
        }
        return array('response' => array('code' => 200), 'body' => json_encode($invoice), 'headers' => array());
    };
    assertSameValue('success', (new \PaymosWooCommerce\Gateway())->process_payment(100)['result'], 'Safely cancelled recovered invoice can be replaced.');
    assertSameValue(array('recover-old', 'cancel-old', 'create-new'), $calls, 'New POST occurs strictly after recovered old invoice cancellation.');
    assertSameValue('inv_new', $order->get_meta('_paymos_invoice_id'), 'New invoice becomes the exact current binding.');
}

function test_creation_intent_save_failure_prevents_invoice_post()
{
    paymos_reset_test_state();
    $order = new class extends FakeOrder { public function save() { return false; } };
    paymos_store_credentials(array('sandbox' => array('api_key' => 'pk_test_key', 'api_secret' => 'sk_test_secret', 'project_id' => 'prj_123', 'webhook_secret' => 'whsec_test', 'base_url' => 'https://api.paymos.io')));
    $apiCalls = 0;
    $GLOBALS['paymos_test_remote_handler'] = static function () use (&$apiCalls) { $apiCalls++; return new WP_Error(); };
    assertSameValue('failure', (new \PaymosWooCommerce\Gateway())->process_payment(100)['result'], 'Failed durable intent save stops checkout.');
    assertSameValue(0, $apiCalls, 'No network create may precede successful durable intent save.');
}


function test_legacy_bound_logged_in_checkout_retry_keeps_client_intent_twice()
{
    $order = paymos_renewal_order_with_invoice('awaiting_client');
    paymos_renewal_setup($order, array(array('invoice_id' => 'inv_old', 'status' => 'awaiting_client', 'payment_url' => 'https://checkout.paymos.io/inv_old'), array('invoice_id' => 'inv_old', 'status' => 'awaiting_client', 'payment_url' => 'https://checkout.paymos.io/inv_old')), $sent);
    assertSameValue('success', (new \PaymosWooCommerce\Gateway())->process_payment(100)['result'], 'First legacy bound checkout adopts exact creation payload.');
    assertSameValue('7', $order->get_meta('_paymos_client_id'), 'Logged-in client snapshot is saved with legacy payload.');
    assertSameValue('success', (new \PaymosWooCommerce\Gateway())->process_payment(100)['result'], 'Second logged-in retry reuses exact payload without validation mismatch.');
    assertSameValue($sent[0], $sent[1], 'Both POST bodies are exactly identical.');
}

function test_blocked_replacement_lost_mutex_cannot_mutate_native_status()
{
    global $wpdb;
    $order = paymos_replacement_changed_order();
    paymos_replacement_setup($order, array(), $unused);
    $GLOBALS['paymos_test_remote_handler'] = static function ($url, $args) use ($wpdb, $order) {
        $wpdb->connectionId = '2'; // wpdb reconnected while the first API call ran.
        if ($args['method'] === 'POST') {
            return array('response' => array('code' => 409), 'body' => json_encode(array('status' => 409, 'code' => 'invoice_cannot_be_cancelled')), 'headers' => array());
        }
        return array('response' => array('code' => 200), 'body' => json_encode(array('invoice_id' => 'inv_old', 'status' => 'paid')), 'headers' => array());
    };
    assertSameValue('failure', (new \PaymosWooCommerce\Gateway())->process_payment(100)['result'], 'Lost mutex stops checkout.');
    assertSameValue(0, count($order->statusUpdates), 'Exception catch must assert mutex ownership before on-hold mutation.');
    assertSameValue(0, count($order->notes), 'Lost owner cannot append mutable review state.');
}


final class NativeHposMetaFixture
{
    public function clear_cached_data(array $ids)
    {
        foreach ($GLOBALS['paymos_test_wc_orders'] ?? array() as $order) {
            if ($order instanceof LayeredCachedOrder && in_array($order->get_id(), $ids, true)) {
                $order->cachedMetadata = null;
                $order->nativeMetaClearCalls++;
            }
        }
        return array_fill_keys($ids, true);
    }
}
class_alias(NativeHposMetaFixture::class, 'Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStoreMeta');
function wc_get_container()
{
    return new class {
        public function get($class) {
            assertSameValue('Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStoreMeta', $class, 'Only native HPOS metadata service is addressed.');
            return new NativeHposMetaFixture();
        }
    };
}

function test_partial_hpos_cache_missing_raw_row_still_invalidates_native_metadata()
{
    paymos_reset_test_state();
    $cached = layeredPaidOrder();
    $cached->databaseRow['paid'] = false;
    $cached->databaseRow['meta']['_paymos_last_status'] = '';
    $cached->rawCachedRow = null; // raw-row miss means its clear cannot evict metadata.
    $cached->cachedMetadata = array_merge($cached->databaseRow['meta'], array('_paymos_last_status' => 'expired', '_paymos_invoice_id' => 'inv_cached_wrong'));
    (new OrderMapper())->apply($cached, publicationPaidEvent());
    assertSameValue(true, $cached->paid, 'Force WC_Data read must not reuse an independent stale HPOS orders_meta entry.');
    assertSameValue('inv_123', $cached->get_meta('_paymos_invoice_id'), 'Native HPOS metadata cache is explicitly invalidated.');
    assertSameValue(1, $cached->nativeMetaClearCalls, 'Addressed native metadata invalidation occurs regardless of raw-row cache miss.');
}
