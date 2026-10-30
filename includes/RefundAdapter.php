<?php

declare(strict_types=1);

namespace PaymosWooCommerce;

use Paymos\Plugin\AmountGuard;
use Paymos\Plugin\CredentialSet;
use Paymos\Plugin\EffectClaim;
use Paymos\Plugin\NativeProjectionResult;
use Paymos\Plugin\NativeRefundProjectorInterface;
use Paymos\Plugin\RefundCoordinator;
use Paymos\Plugin\RefundDiscovery;
use Paymos\Plugin\RefundIntent;
use Paymos\Plugin\RefundStates;
use Paymos\Plugin\VerifiedRefund;
use Paymos\Webhook\MultiEnvironmentWebhookVerifier;

defined('ABSPATH') || exit;

final class RefundAdapter implements NativeRefundProjectorInterface
{
    public const HOOK = 'paymos_woocommerce_refunds_reconcile';
    private $store;
    private $clientFactory;
    private $configProvider;
    private static $finalizing;
    private static $mailContext;

    public function __construct(?RefundIntentStore $store = null, ?callable $clientFactory = null, ?callable $configProvider = null)
    {
        $this->store = $store ?: new RefundIntentStore();
        $this->clientFactory = $clientFactory;
        $this->configProvider = $configProvider ?: array(Config::class, 'environment_config');
    }

    public static function register()
    {
        add_action('wp_ajax_woocommerce_refund_line_items', array(__CLASS__, 'nativeAjax'), 0);
        add_action('wp_ajax_paymos_refund', array(__CLASS__, 'managedAjax'));
        add_action('woocommerce_admin_order_data_after_order_details', array(__CLASS__, 'panel'));
        add_action('woocommerce_before_order_refund_object_save', array(__CLASS__, 'guardRefundSave'), 0);
        add_action('woocommerce_before_order_object_save', array(__CLASS__, 'guardOrderSave'), 0);
        add_action('woocommerce_create_refund', array(__CLASS__, 'markRefund'), 5, 2);
        add_filter('woocommerce_email_enabled_customer_refunded_order', array(__CLASS__, 'emailEnabled'), 10, 3);
        add_filter('woocommerce_email_enabled_customer_partially_refunded_order', array(__CLASS__, 'emailEnabled'), 10, 3);
        add_filter('rest_pre_dispatch', array(__CLASS__, 'callback'), 10, 3);
        add_filter('cron_schedules', array(__CLASS__, 'cronSchedules'));
        add_action('init', array(__CLASS__, 'schedule'));
        add_action(self::HOOK, array(__CLASS__, 'run'));
    }

    public static function schedule()
    {
        if (!wp_next_scheduled(self::HOOK)) {
            wp_schedule_event(time() + 60, 'paymos_refund_minute', self::HOOK);
        }
    }

    public static function cronSchedules($schedules)
    {
        $schedules['paymos_refund_minute'] = array('interval' => 60, 'display' => __('Every minute', 'paymos-for-woocommerce'));
        return $schedules;
    }

    public static function unschedule()
    {
        wp_clear_scheduled_hook(self::HOOK);
    }

    public static function nativeAjax()
    {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Preserve native monetary strings for full-draft validation; scalar controls are validated below.
        $input = wp_unslash($_POST);
        $id = isset($input['order_id']) && is_scalar($input['order_id']) ? absint($input['order_id']) : 0;
        $order = wc_get_order($id);
        if (!$order || $order->get_payment_method() !== 'paymos') { return; }
        if (!check_ajax_referer('order-item', 'security', false)
            || !current_user_can('edit_shop_orders') || !current_user_can('edit_shop_order', $id)) {
            wp_send_json_error(array('error' => __('You do not have permission to refund this order.', 'paymos-for-woocommerce')), 200);
            return;
        }
        self::ajax($id, RefundStates::FULL, $input);
    }

    public static function managedAjax()
    {
        if (!check_ajax_referer('order-item', 'security', false)
            || !current_user_can('edit_shop_orders')) {
            wp_send_json_error(array('error' => __('You do not have permission to refund this order.', 'paymos-for-woocommerce')), 200);
            return;
        }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Preserve unexpected amount fields for downstream rejection and exact native values; validate scalar controls separately.
        $input = wp_unslash($_POST);
        $id = isset($input['order_id']) && is_scalar($input['order_id']) ? absint($input['order_id']) : 0;
        if (!current_user_can('edit_shop_order', $id)) {
            wp_send_json_error(array('error' => __('You do not have permission to refund this order.', 'paymos-for-woocommerce')), 200);
            return;
        }
        $mode = !array_key_exists('mode', $input) ? RefundStates::OVERPAYMENT
            : (is_string($input['mode']) && in_array($input['mode'], array('summary', RefundStates::OVERPAYMENT), true) ? $input['mode'] : '');
        self::ajax($id, $mode, $input);
    }

    private static function ajax($id, $mode, array $input)
    {
        try {
            $adapter = new self();
            if ($mode === 'summary') {
                wp_send_json_success($adapter->summary((string) $id));
                return;
            }
            $token = isset($input['paymos_refund_token']) && is_string($input['paymos_refund_token'])
                ? sanitize_text_field($input['paymos_refund_token']) : '';
            if ($token !== ($input['paymos_refund_token'] ?? null)) { $token = ''; }
            $intent = $adapter->request((string) $id, $mode, $token, $input);
            // Woo's normal success response would book a refund before cash completion.
            wp_send_json(array('success' => false, 'data' => array('error' => __('Refund requested. The order is updated when the refund completes.', 'paymos-for-woocommerce')), 'paymos_refund' => $adapter->view($intent)));
        } catch (\Throwable $error) {
            Logger::error('Paymos refund entry rejected: ' . $error->getMessage(), array('order_id' => $id));
            wp_send_json_error(array('error' => __('The refund was not started. With Debug logging on, the reason is written to the WooCommerce logs.', 'paymos-for-woocommerce')), 200);
        }
    }

    public function request(string $paymentKey, string $mode, string $token, array $input): RefundIntent
    {
        if (!preg_match('/^[a-zA-Z0-9_-]{16,64}$/D', $token)
            || !in_array($mode, array(RefundStates::FULL, RefundStates::OVERPAYMENT), true)) {
            throw new \RuntimeException('refund_request_invalid');
        }
        return $this->store->withPaymentLock($paymentKey, function () use ($paymentKey, $mode, $token, $input) {
            $installation = $this->installationId();
            $old = $this->store->findByRequestToken($installation, $token);
            if ($old !== null) {
                if ($old->paymentKey() !== $paymentKey || $old->mode() !== $mode) {
                    throw new \RuntimeException('refund_request_conflict');
                }
                if ($mode === RefundStates::FULL) {
                    $this->assertInput($old->fullDraft(), $input);
                }
                $this->coordinator($old)->submit($old->operationId());
                $this->reconcile($old->operationId());
                return $this->store->getIntent($old->operationId());
            }
            $order = $this->store->loadOrder();
            $this->assertMapping($order);
            $draft = $mode === RefundStates::FULL ? $this->draft($order, $input) : null;
            $intent = new RefundIntent(array(
                'operation_id' => wp_generate_uuid4(), 'request_token' => $token,
                'installation_id' => $installation, 'platform' => 'woocommerce', 'native_payment_key' => $paymentKey,
                'invoice_id' => (string) $order->get_meta('_paymos_invoice_id', true),
                'project_id' => (string) $order->get_meta('_paymos_project_id', true),
                'environment' => (string) $order->get_meta('_paymos_environment', true), 'mode' => $mode, 'full_draft' => $draft,
            ));
            $reason = $this->localPreflight($intent);
            if ($reason !== null) { throw new \RuntimeException(esc_html($reason)); }
            $intent = $this->store->createIntent($intent);
            $this->coordinator($intent)->submit($intent->operationId());
            $this->reconcile($intent->operationId());
            return $this->store->getIntent($intent->operationId());
        });
    }

    public function preflight(RefundIntent $intent): ?string
    {
        $reason = $this->localPreflight($intent);
        if ($reason !== null) { return $reason; }
        try {
            $invoice = $this->client($intent->environment())->invoices()->get($intent->invoiceId());
            $this->store->assertOwned();
            $this->assertInvoice($invoice, $intent);
            $fresh = $this->store->loadOrder();
            $this->assertMapping($fresh, $intent);
            $this->assertOriginalPaid($fresh);
            if ($intent->mode() === RefundStates::FULL && !$this->matchesDraft($fresh, $intent->fullDraft())) {
                return 'refund_native_draft_changed';
            }
            $summary = isset($invoice['refund_summary']) ? $invoice['refund_summary'] : array();
            $field = $intent->mode() === RefundStates::FULL ? 'can_refund_full' : 'can_refund_overpayment';
            if (!isset($summary[$field]) || $summary[$field] !== true) {
                return 'refund_server_not_eligible';
            }
            return null;
        } catch (\Throwable $error) {
            return $error->getMessage();
        }
    }

    private function localPreflight(RefundIntent $intent): ?string
    {
        try {
            $order = $this->store->loadOrder();
            $this->assertMapping($order, $intent);
            $this->assertOriginalPaid($order);
            $this->store->assertNoExternalReview();
            $this->store->assertTransactionalTables();
            $config = call_user_func($this->configProvider, $intent->environment());
            if (!CredentialSet::hasCapability($config, 'invoice_refunds_v1')
                || !isset($config['refund_contract_version']) || $config['refund_contract_version'] !== 1) {
                return 'refund_reconnect_required';
            }
            if ($intent->mode() === RefundStates::FULL && !$this->matchesDraft($order, $intent->fullDraft())) {
                return 'refund_native_draft_changed';
            }
            return null;
        } catch (\Throwable $error) {
            return $error->getMessage();
        }
    }

    public function inspect(string $operationId): NativeProjectionResult
    {
        $intent = $this->store->getIntent($operationId);
        $order = $this->store->loadOrder();
        try {
            $this->assertMapping($order, $intent);
            if ($intent->mode() === RefundStates::OVERPAYMENT) {
                $marker = $order->get_meta('_paymos_refund_note_' . $operationId, true);
                if (is_array($marker)) {
                    $note = get_comment((int) $marker['id']);
                    $snapshot = $intent->observation();
                    if (!$note || (string) $note->comment_post_ID !== $intent->paymentKey()
                        || strpos($note->comment_content, '[pmrf:' . $operationId . ']') === false
                        || $marker['withdrawal_id'] !== $intent->withdrawalId() || $snapshot === null
                        || !AmountGuard::amountsEqual($marker['amount'], $snapshot->principalDecimal())
                        || $marker['asset'] !== $snapshot->currency() . '/' . $snapshot->network()) {
                        return $this->ambiguous($intent, 'refund_cash_note_conflict');
                    }
                    return new NativeProjectionResult(NativeProjectionResult::EXACT_MATCH, $intent->mode(),
                        (string) $marker['id'], null, null, $marker['amount'], $marker['asset']);
                }
            } else {
                $found = array();
                $refunds = $order->get_refunds();
                foreach ($refunds as $refund) {
                    $refund = $this->store->loadNative($refund->get_id());
                    if ((string) $refund->get_meta('_paymos_refund_operation_id', true) === $operationId) { $found[] = $refund; }
                }
                if (count($found) > 0) {
                    $draft = $intent->fullDraft();
                    $refund = $found[0];
                    if (count($found) !== 1 || count($refunds) !== 1 || (string) $refund->get_parent_id() !== $intent->paymentKey()
                        || (string) $refund->get_meta('_paymos_refund_withdrawal_id', true) !== $intent->withdrawalId()
                        || (string) $refund->get_meta('_paymos_refund_payload_hash', true) !== $intent->toArray()['payload_hash']
                        || !AmountGuard::amountsEqual((string) $refund->get_amount(), $draft['amount_decimal'])
                        || !AmountGuard::amountsEqual((string) $order->get_total_refunded(), $draft['amount_decimal'])
                        || $refund->get_currency() !== $draft['currency'] || !$this->matchesDraft($order, $draft)) {
                        return $this->ambiguous($intent, 'refund_native_document_conflict');
                    }
                    return new NativeProjectionResult(NativeProjectionResult::EXACT_MATCH, $intent->mode(),
                        (string) $refund->get_id(), $draft['amount_minor'], $draft['currency']);
                }
            }
            $this->assertOriginalPaid($order);
            if ($intent->mode() === RefundStates::FULL && !$this->matchesDraft($order, $intent->fullDraft())) {
                return $this->ambiguous($intent, 'refund_native_draft_changed');
            }
            return new NativeProjectionResult(NativeProjectionResult::ABSENT, $intent->mode());
        } catch (\Throwable $error) {
            return $this->ambiguous($intent, $error->getMessage());
        }
    }

    public function applyCompleted(RefundIntent $intent, VerifiedRefund $refund): NativeProjectionResult
    {
        $this->store->assertNativeTransaction();
        if ($refund->status() !== 'completed' || $refund->mode() !== $intent->mode()
            || $refund->withdrawalId() !== $intent->withdrawalId()) {
            return $this->ambiguous($intent, 'refund_completion_not_verified');
        }
        $existing = $this->inspect($intent->operationId());
        if ($existing->kind() !== NativeProjectionResult::ABSENT) { return $existing; }
        $order = $this->store->loadOrder();
        if ($intent->mode() === RefundStates::OVERPAYMENT) {
            $asset = $refund->currency() . '/' . $refund->network();
            $id = $order->add_order_note(__('Paymos overpayment refund completed. The order stays paid and stock is unchanged.', 'paymos-for-woocommerce') . ' [pmrf:' . $intent->operationId() . '] ' . $refund->principalDecimal() . ' ' . $asset, false);
            if (!$id) { throw new \RuntimeException('refund_cash_note_failed'); }
            $order->update_meta_data('_paymos_refund_note_' . $intent->operationId(), array('id' => (string) $id,
                'withdrawal_id' => $refund->withdrawalId(), 'amount' => $refund->principalDecimal(), 'asset' => $asset));
            if (!$order->save()) { throw new \RuntimeException('refund_cash_marker_failed'); }
        } else {
            $draft = $intent->fullDraft();
            self::$finalizing = $intent;
            try {
                $native = wc_create_refund(array('order_id' => (int) $intent->paymentKey(),
                    'amount' => $draft['amount_decimal'], 'reason' => $draft['reason'], 'line_items' => $draft['line_items'],
                    'refund_payment' => false, 'restock_items' => $draft['restock_items']));
                if (is_wp_error($native) || !$native || !$native->get_id()) {
                    throw new \RuntimeException('refund_native_write_failed');
                }
            } finally {
                self::$finalizing = null;
            }
        }
        return $this->inspect($intent->operationId());
    }

    public static function guardRefundSave($refund)
    {
        $order = wc_get_order($refund->get_parent_id());
        if (!$order || $order->get_payment_method() !== 'paymos') { return; }
        self::assertFinalizing($order->get_id());
    }

    public static function guardOrderSave($order)
    {
        if ($order->get_payment_method() !== 'paymos') { return; }
        $changes = $order->get_changes();
        if (!isset($changes['status']) || $changes['status'] !== 'refunded') { return; }
        self::assertFinalizing($order->get_id());
    }

    private static function assertFinalizing($orderId)
    {
        if (self::$finalizing !== null && self::$finalizing->paymentKey() === (string) $orderId) { return; }
        // WooCommerce resumes after caught save exceptions, so refusal must stop the request.
        wp_die(esc_html__('Nothing was refunded. Open the order in WooCommerce admin and refund it there.', 'paymos-for-woocommerce'),
            '', array('response' => 403));
    }

    public static function markRefund($refund, $args)
    {
        $intent = self::$finalizing;
        if ($intent === null || (string) $args['order_id'] !== $intent->paymentKey()) { return; }
        if (!isset($args['refund_payment']) || $args['refund_payment'] !== false) {
            throw new \RuntimeException('refund_projection_must_be_offline');
        }
        $refund->update_meta_data('_paymos_refund_operation_id', $intent->operationId());
        $refund->update_meta_data('_paymos_refund_withdrawal_id', $intent->withdrawalId());
        $refund->update_meta_data('_paymos_refund_payload_hash', $intent->toArray()['payload_hash']);
    }

    public static function emailEnabled($enabled, $order, $email = null)
    {
        if (!is_object($email) || !isset($email->refund) || !is_object($email->refund)) { return $enabled; }
        $operation = (string) $email->refund->get_meta('_paymos_refund_operation_id', true);
        if ($operation === '') { return $enabled; }
        return self::$mailContext !== null && self::$mailContext['operation'] === $operation
            && self::$mailContext['refund'] === (string) $email->refund->get_id()
            && self::$mailContext['email'] === $email ? $enabled : false;
    }

    public function reconcile(string $operationId): string
    {
        $intent = $this->store->getIntent($operationId);
        return $this->store->withPaymentLock($intent->paymentKey(), function () use ($operationId) {
            $intent = $this->store->getIntent($operationId);
            $action = $this->coordinator($intent)->reconcile($operationId);
            $this->store->assertOwned();
            $this->dispatchMail($this->store->getIntent($operationId));
            return $action;
        });
    }

    private function dispatchMail(RefundIntent $intent)
    {
        if ($intent->mode() !== RefundStates::FULL || $intent->cashState() !== RefundStates::COMPLETED
            || $intent->projectionState() !== RefundStates::PROJECTION_APPLIED) { return; }
        $operation = $intent->operationId();
        $key = 'customer_refund:1';
        $effects = $intent->toArray()['effects'];
        if (isset($effects[$key])) {
            if ($effects[$key] === RefundStates::EFFECT_STARTED) {
                $this->store->recordEffectOutcome($operation, 'customer_refund', 1, RefundStates::EFFECT_UNKNOWN);
                $this->store->recordState($operation, $intent->cashState(), RefundStates::NATIVE_REVIEW, 'refund_mail_unknown');
            }
            return;
        }
        if (!$intent->fullDraft()['send_email']) {
            $this->store->recordEffectOutcome($operation, 'customer_refund', 1, RefundStates::EFFECT_SKIPPED);
            return;
        }
        $emails = WC()->mailer()->get_emails();
        if (!isset($emails['WC_Email_Customer_Refunded_Order'])) { throw new \RuntimeException('refund_mailer_unavailable'); }
        $email = $emails['WC_Email_Customer_Refunded_Order'];
        if ($this->store->claimEffect($operation, 'customer_refund', 1)->kind() !== EffectClaim::CLAIMED) { return; }
        $projection = NativeProjectionResult::fromStorage($intent->toArray()['projection']);
        self::$mailContext = array('operation' => $operation, 'refund' => $projection->nativeId(), 'email' => $email);
        $sent = null;
        $capture = static function ($result, $id = '', $object = null) use ($email, $projection, &$sent) {
            if ($object === $email && isset($email->refund) && is_object($email->refund)
                && (string) $email->refund->get_id() === $projection->nativeId()) { $sent = (bool) $result; }
        };
        add_filter('woocommerce_email_sent', $capture, 10, 3);
        try {
            $email->trigger_full((int) $intent->paymentKey(), (int) $projection->nativeId());
            $this->store->assertOwned();
            $state = $sent === true ? RefundStates::EFFECT_DELIVERED : RefundStates::EFFECT_UNKNOWN;
            $this->store->recordEffectOutcome($operation, 'customer_refund', 1, $state);
            if ($state === RefundStates::EFFECT_UNKNOWN) {
                $this->store->recordState($operation, $intent->cashState(), RefundStates::NATIVE_REVIEW, 'refund_mail_unknown');
            }
        } catch (\Throwable $error) {
            $this->store->recordEffectOutcome($operation, 'customer_refund', 1, RefundStates::EFFECT_UNKNOWN);
            $this->store->recordState($operation, $intent->cashState(), RefundStates::NATIVE_REVIEW, 'refund_mail_unknown');
        } finally {
            self::$mailContext = null;
            remove_filter('woocommerce_email_sent', $capture, 10);
        }
    }

    public static function callback($result, $server, $request)
    {
        if ($result !== null || $request->get_route() !== '/paymos/v1/webhook' || $request->get_method() !== 'POST') { return $result; }
        $body = json_decode($request->get_body(), true);
        if (!is_array($body) || !isset($body['event_type']) || !is_string($body['event_type'])
            || strpos($body['event_type'], 'withdrawal.') !== 0) { return $result; }
        return (new self())->handleCallback($request);
    }

    public function handleCallback($request)
    {
        $events = new EventStore();
        try {
            $verified = (new MultiEnvironmentWebhookVerifier(Config::webhook_secrets(), $events))
                ->process($request->get_header('x-webhook-signature'), $request->get_body());
            $event = $verified->event()->toArray();
            if (strpos($verified->event()->type(), 'withdrawal.') !== 0) {
                throw new \RuntimeException('refund_callback_type_invalid');
            }
            $data = isset($event['data']) && is_array($event['data']) ? $event['data'] : array();
            if (!isset($data['invoice_id']) || !is_string($data['invoice_id']) || $data['invoice_id'] === '') {
                $events->commit();
                return new \WP_REST_Response(array('ok' => true, 'ignored' => true), 200);
            }
            foreach (array('withdrawal_id', 'external_order_id', 'mode') as $field) {
                if (!isset($data[$field]) || !is_string($data[$field]) || $data[$field] === '') {
                    throw new \RuntimeException('refund_callback_scope_missing');
                }
            }
            $intent = $this->store->findByExternalId($data['external_order_id']);
            if ($intent === null) {
                $this->observeExternal($data, $verified->environment());
                $events->commit();
                return new \WP_REST_Response(array('ok' => true), 200);
            }
            if ($intent->environment() !== $verified->environment() || !isset($data['is_test']) || !is_bool($data['is_test'])
                || $data['is_test'] !== ($intent->environment() === 'sandbox') || $data['invoice_id'] !== $intent->invoiceId()
                || (isset($data['project_id']) && $data['project_id'] !== $intent->projectId()) || $data['mode'] !== $intent->mode()
                || ($intent->withdrawalId() !== null && $data['withdrawal_id'] !== $intent->withdrawalId())) {
                throw new \RuntimeException('refund_callback_scope_conflict');
            }
            $this->reconcile($intent->operationId());
            $events->commit();
            return new \WP_REST_Response(array('ok' => true), 200);
        } catch (\Paymos\Exception\DuplicateEventException $error) {
            return new \WP_REST_Response(array('ok' => true, 'duplicate' => true), 200);
        } catch (\Paymos\Exception\SignatureMismatchException $error) {
            return new \WP_REST_Response(array('error' => 'bad_signature'), 401);
        } catch (\Paymos\Exception\TimestampSkewException $error) {
            return new \WP_REST_Response(array('error' => 'bad_timestamp'), 401);
        } catch (\Throwable $error) {
            try { $events->release(); } catch (\RuntimeException $releaseError) { /* Keep the retryable response. */ }
            Logger::error('Paymos refund callback failed: ' . $error->getMessage());
            return new \WP_REST_Response(array('error' => 'refund_retry_required'), 409);
        }
    }

    public static function run()
    {
        $adapter = new self();
        $cursor = get_option('paymos_woocommerce_refund_cursor', null);
        $page = $adapter->store->listDue(new \DateTimeImmutable('now'), 100, is_string($cursor) ? $cursor : null);
        foreach ($page->items() as $intent) {
            try {
                if ($intent->cashState() === RefundStates::NOT_SUBMITTED) { $adapter->coordinator($intent)->submit($intent->operationId()); }
                $adapter->reconcile($intent->operationId());
            } catch (\Throwable $error) {
                Logger::error('Paymos refund reconciliation failed: ' . $error->getMessage());
            } finally {
                try {
                    $adapter->store->withPaymentLock($intent->paymentKey(), function () use ($adapter, $intent) { $adapter->store->defer($intent->operationId()); });
                } catch (\Throwable $error) {
                    Logger::error('Paymos refund backoff failed: ' . $error->getMessage());
                }
            }
        }
        update_option('paymos_woocommerce_refund_cursor', $page->nextCursor(), false);
        $adapter->discoverExternal();
    }

    public function observeExternal(array $signal, string $environment): void
    {
        if (!isset($signal['withdrawal_id'], $signal['invoice_id'], $signal['external_order_id'], $signal['mode'])
            || ($signal['is_test'] ?? null) !== ($environment === 'sandbox')) {
            throw new \RuntimeException('refund_callback_scope_missing');
        }
        $client = $this->client($environment);
        $wire = $client->refunds()->get($signal['withdrawal_id']);
        $invoice = $client->invoices()->get($signal['invoice_id']);
        $refund = VerifiedRefund::fromApi($wire, $invoice, new \DateTimeImmutable());
        foreach (array('withdrawal_id' => 'withdrawalId', 'invoice_id' => 'invoiceId', 'mode' => 'mode', 'external_order_id' => 'externalOrderId') as $field => $method) {
            if ($signal[$field] !== $refund->$method()) { throw new \RuntimeException('refund_callback_scope_conflict'); }
        }
        if ($refund->environment() !== $environment || (isset($signal['project_id']) && $signal['project_id'] !== $refund->projectId())) {
            throw new \RuntimeException('refund_callback_scope_conflict');
        }
        $external = $invoice['order']['external_id'] ?? '';
        if (!is_string($external) || !preg_match('/^wc_([1-9][0-9]*)(?:_|$)/D', $external, $match)) {
            throw new \RuntimeException('refund_external_order_unmapped');
        }
        $this->store->withPaymentLock($match[1], function () use ($refund, $external, $environment) {
            $order = $this->store->loadOrder();
            $this->assertMapping($order);
            $config = call_user_func($this->configProvider, $environment);
            if ($order->get_meta('_paymos_external_order_id', true) !== $external
                || $order->get_meta('_paymos_invoice_id', true) !== $refund->invoiceId()
                || $order->get_meta('_paymos_project_id', true) !== $refund->projectId()
                || $order->get_meta('_paymos_environment', true) !== $environment
                || ($config['project_id'] ?? null) !== $refund->projectId()) {
                throw new \RuntimeException('refund_external_scope_conflict');
            }
            $this->store->observeExternal($refund);
        });
    }

    public function discoverExternal(): int
    {
        $count = 0;
        foreach (array('sandbox', 'live') as $environment) {
            try {
                $config = call_user_func($this->configProvider, $environment);
                if (!isset($config['project_id']) || !is_string($config['project_id']) || $config['project_id'] === '') { continue; }
                $key = 'paymos_wc_external_cursor_' . $environment . '_' . substr(hash('sha256', $config['project_id']), 0, 16);
                $cursor = get_option($key, null);
                $result = RefundDiscovery::page($this->client($environment), $environment, is_string($cursor) ? $cursor : null,
                    function ($signal, $env) {
                        $intent = $this->store->findByWithdrawalId($signal['withdrawal_id']) ?: $this->store->findByExternalId($signal['external_order_id']);
                        if ($intent !== null) { return false; }
                        else { $this->observeExternal($signal, $env); }
                    });
                update_option($key, $result['next_cursor'], false);
                $count += $result['checked'];
                if ($result['errors']) { Logger::error('Paymos external refund observations require another check.', array('withdrawal_ids' => $result['errors'])); }
            } catch (\Throwable $error) { Logger::error('Paymos external refund discovery failed.'); }
        }
        return $count;
    }

    public function summary(string $paymentKey): array
    {
        return $this->store->withPaymentLock($paymentKey, function () {
            $order = $this->store->loadOrder();
            $this->assertMapping($order);
            $invoice = $this->client((string) $order->get_meta('_paymos_environment', true))->invoices()->get((string) $order->get_meta('_paymos_invoice_id', true));
            $this->store->assertOwned();
            if (!isset($invoice['invoice_id'], $invoice['project_id'], $invoice['is_test'])
                || $invoice['invoice_id'] !== $order->get_meta('_paymos_invoice_id', true)
                || $invoice['project_id'] !== $order->get_meta('_paymos_project_id', true)
                || $invoice['is_test'] !== ($order->get_meta('_paymos_environment', true) === 'sandbox')) {
                throw new \RuntimeException('refund_invoice_scope_conflict');
            }
            $summary = isset($invoice['refund_summary']) && is_array($invoice['refund_summary']) ? $invoice['refund_summary'] : array();
            $blocked = false;
            try { $this->assertOriginalPaid($order); } catch (\Throwable $error) { $blocked = true; }
            try { $this->store->assertNoExternalReview(); } catch (\Throwable $error) { $blocked = true; }
            $config = call_user_func($this->configProvider, (string) $order->get_meta('_paymos_environment', true));
            if (!CredentialSet::hasCapability($config, 'invoice_refunds_v1')
                || !isset($config['refund_contract_version']) || $config['refund_contract_version'] !== 1) { $blocked = true; }
            foreach ($this->store->forPayment((string) $order->get_id()) as $intent) {
                if ($this->store->blocksNewIntent($intent)) { $blocked = true; }
            }
            if ($blocked) { $summary['can_refund_full'] = false; $summary['can_refund_overpayment'] = false; }
            return $summary;
        });
    }

    public static function panel($order)
    {
        if ($order->get_payment_method() !== 'paymos' || !current_user_can('edit_shop_order', $order->get_id())) { return; }
        $adapter = new self();
        $tokens = array(RefundStates::FULL => wp_generate_uuid4(), RefundStates::OVERPAYMENT => wp_generate_uuid4());
        try {
            RefundIntentStore::install();
            $intents = $adapter->store->forPayment((string) $order->get_id());
            echo '<div id="paymos-refunds"><p>' . esc_html__('Paymos refunds', 'paymos-for-woocommerce') . '</p>';
            foreach ($intents as $intent) {
                if ($adapter->store->blocksNewIntent($intent)) {
                    $tokens[$intent->mode()] = $intent->toArray()['request_token'];
                }
                echo '<dl>';
                foreach ($adapter->view($intent) as $value) {
                    if ($value !== null) { echo '<dt>' . esc_html__('Refund', 'paymos-for-woocommerce') . '</dt><dd><code>' . esc_html($value) . '</code></dd>'; }
                }
                echo '</dl>';
            }
            $externalRows = $order->get_meta('_paymos_external_refunds', true);
            foreach (is_array($externalRows) ? $externalRows : array() as $row) {
                $cash = $row['observation'];
                echo '<p><code>' . esc_html(implode(' ', array($cash['withdrawal_id'], $cash['mode'], $cash['status'],
                    $cash['amount'], $cash['currency'] . '/' . $cash['network'], $row['review_reason'] ?? ''))) . '</code></p>';
            }
            echo '<p id="paymos-refund-summary"></p><p id="paymos-refund-error" role="alert"></p><button type="button" disabled class="button" id="paymos-overpayment">' . esc_html__('Refund overpayment', 'paymos-for-woocommerce') . '</button></div>';
            $settings = array('order_id' => $order->get_id(), 'security' => wp_create_nonce('order-item'), 'tokens' => $tokens);
            echo '<script>jQuery(function($){var p=' . wp_json_encode($settings) . ';
                $.ajaxPrefilter(function(o){if(typeof o.data!=="string")return;var d=new URLSearchParams(o.data);
                    if(d.get("action")!=="woocommerce_refund_line_items"||d.get("order_id")!==String(p.order_id))return;
                    d.set("paymos_refund_token",p.tokens.full);o.data=d.toString();
                });
                $(document).ajaxComplete(function(e,x){if(x.responseJSON&&x.responseJSON.paymos_refund)window.location.reload();});
                function refundError(r){if(r.data&&r.data.error)$("#paymos-refund-error").text(r.data.error);}
                $("#paymos-overpayment").on("click",function(){var b=$(this);b.prop("disabled",true);$("#paymos-refund-error").text("");$.post(ajaxurl,{action:"paymos_refund",order_id:p.order_id,security:p.security,paymos_refund_token:p.tokens.overpayment},function(r){if(r.paymos_refund){window.location.reload();return;}refundError(r);b.prop("disabled",false);});});
                $.post(ajaxurl,{action:"paymos_refund",mode:"summary",order_id:p.order_id,security:p.security},function(r){if(r.success){var a=r.data.refund_asset||{};
                    $("#paymos-refund-summary").text("' . esc_js(__('Full refund:', 'paymos-for-woocommerce')) . ' "+(r.data.full_refund_amount||"0")+" / ' . esc_js(__('Overpayment:', 'paymos-for-woocommerce')) . ' "+(r.data.overpayment_refund_amount||"0")+" "+(a.currency||"")+"/"+(a.network||""));
                    $("#paymos-overpayment").prop("disabled",r.data.can_refund_overpayment!==true);}else{refundError(r);}});
            });</script>';
        } catch (\Throwable $error) {
            echo '<p>' . esc_html__('Paymos refunds could not be loaded. Reload the page to try again.', 'paymos-for-woocommerce') . '</p>';
            Logger::error('Paymos refund panel failed: ' . $error->getMessage());
        }
    }

    private function view(RefundIntent $intent): array
    {
        $observation = $intent->observation();
        return array('operation_id' => $intent->operationId(), 'mode' => $intent->mode(), 'cash_state' => $intent->cashState(),
            'native_state' => $intent->projectionState(), 'review_reason' => $intent->toArray()['review_reason'],
            'crypto_amount' => $observation === null ? null : $observation->principalDecimal(),
            'crypto_asset' => $observation === null ? null : $observation->currency() . '/' . $observation->network(),
            'native_amount' => $intent->fullDraft() === null ? null : $intent->fullDraft()['amount_decimal']);
    }

    private function coordinator(RefundIntent $intent): RefundCoordinator
    {
        return new RefundCoordinator($this->client($intent->environment()), $this->store, $this);
    }

    private function client($environment)
    {
        if ($this->clientFactory !== null) { return call_user_func($this->clientFactory, $environment); }
        return ClientFactory::create(call_user_func($this->configProvider, $environment));
    }

    private function installationId(): string
    {
        $key = 'paymos_woocommerce_refund_installation';
        $id = get_option($key, '');
        if ($id === '') {
            add_option($key, wp_generate_uuid4(), '', false);
            $id = get_option($key, '');
        }
        if (!is_string($id) || !preg_match('/^[a-f0-9-]{36}$/D', $id)) { throw new \RuntimeException('refund_installation_missing'); }
        return $id;
    }

    private function assertMapping($order, ?RefundIntent $intent = null)
    {
        if (!$order || $order->get_payment_method() !== 'paymos' || $order->get_type() !== 'shop_order') {
            throw new \RuntimeException('refund_payment_mapping_invalid');
        }
        foreach (array('_paymos_invoice_id', '_paymos_project_id', '_paymos_environment', '_paymos_external_order_id') as $key) {
            if ((string) $order->get_meta($key, true) === '') { throw new \RuntimeException('refund_payment_mapping_missing'); }
        }
        $environment = (string) $order->get_meta('_paymos_environment', true);
        $config = call_user_func($this->configProvider, $environment);
        if (!isset($config['project_id']) || $config['project_id'] !== $order->get_meta('_paymos_project_id', true)
            || ($intent !== null && ((string) $order->get_id() !== $intent->paymentKey()
                || $environment !== $intent->environment() || $order->get_meta('_paymos_invoice_id', true) !== $intent->invoiceId()
                || $config['project_id'] !== $intent->projectId()))) {
            throw new \RuntimeException('refund_payment_scope_conflict');
        }
    }

    private function assertOriginalPaid($order)
    {
        if (!$order->is_paid() || !AmountGuard::amountsEqual((string) $order->get_total_refunded(), '0')
            || count($order->get_refunds()) !== 0 || !OrderAmountGuard::currentMatchesSnapshot($order)
            || (string) $order->get_meta('_paymos_applied_paid_invoice_id', true) !== (string) $order->get_meta('_paymos_invoice_id', true)
            || !in_array($order->get_meta('_paymos_last_status', true), array('paid', 'paid_over'), true)) {
            throw new \RuntimeException('refund_original_full_paid_required');
        }
    }

    private function assertInvoice(array $invoice, RefundIntent $intent)
    {
        if (!isset($invoice['invoice_id'], $invoice['project_id'], $invoice['is_test'], $invoice['order']['external_id'])
            || $invoice['invoice_id'] !== $intent->invoiceId() || $invoice['project_id'] !== $intent->projectId()
            || $invoice['is_test'] !== ($intent->environment() === 'sandbox')
            || $invoice['order']['external_id'] !== $this->store->loadOrder()->get_meta('_paymos_external_order_id', true)) {
            throw new \RuntimeException('refund_invoice_scope_conflict');
        }
    }

    private function draft($order, array $input): array
    {
        $scale = wc_get_price_decimals();
        $total = AmountGuard::normalizeDecimal((string) $order->get_total());
        $draft = array('amount_decimal' => $total, 'amount_minor' => self::minor($total, $scale),
            'currency' => $order->get_currency(), 'precision' => $scale,
            'reason' => isset($input['refund_reason']) && is_string($input['refund_reason']) ? sanitize_text_field($input['refund_reason']) : '',
            'line_items' => array(), 'restock_items' => isset($input['restock_refunded_items']) && $input['restock_refunded_items'] === 'true',
            'send_email' => get_option('woocommerce_customer_refunded_order_settings', array())['enabled'] ?? 'yes');
        $draft['send_email'] = $draft['send_email'] === 'yes';
        $draft['snapshot'] = $this->snapshot($order);
        $qty = $this->inputMap($input, 'line_item_qtys');
        $totals = $this->inputMap($input, 'line_item_totals');
        $taxes = $this->inputMap($input, 'line_item_tax_totals');
        if ($qty || $totals || $taxes) {
            foreach ($order->get_items(array('line_item', 'fee', 'shipping')) as $id => $item) {
                $itemTaxes = $item->get_taxes()['total'];
                if (!isset($totals[$id]) || !AmountGuard::amountsEqual((string) $totals[$id], (string) $item->get_total())
                    || !isset($qty[$id]) || !AmountGuard::amountsEqual((string) $qty[$id], (string) $item->get_quantity())) {
                    throw new \RuntimeException('refund_partial_draft_rejected');
                }
                foreach ($itemTaxes as $taxId => $tax) {
                    if (!isset($taxes[$id][$taxId]) || !AmountGuard::amountsEqual((string) $taxes[$id][$taxId], (string) $tax)) {
                        throw new \RuntimeException('refund_partial_tax_rejected');
                    }
                }
                if (isset($taxes[$id]) && count($taxes[$id]) !== count($itemTaxes)) {
                    throw new \RuntimeException('refund_foreign_tax_rejected');
                }
                $draft['line_items'][$id] = array('qty' => (string) $item->get_quantity(),
                    'refund_total' => (string) $item->get_total(), 'refund_tax' => $itemTaxes);
            }
            if (count($qty) !== count($draft['line_items']) || count($totals) !== count($draft['line_items'])) {
                throw new \RuntimeException('refund_foreign_items_rejected');
            }
        } elseif ($draft['restock_items']) {
            throw new \RuntimeException('refund_restock_items_missing');
        }
        $this->assertInput($draft, $input);
        return $draft;
    }

    private function assertInput(array $draft, array $input)
    {
        if (!isset($input['api_refund']) || $input['api_refund'] !== 'true'
            || !isset($input['refund_amount']) || !is_scalar($input['refund_amount'])
            || !AmountGuard::amountsEqual((string) $input['refund_amount'], $draft['amount_decimal'])
            || (isset($input['refunded_amount']) && !AmountGuard::amountsEqual((string) $input['refunded_amount'], '0'))
            || (isset($input['refund_reason']) && sanitize_text_field($input['refund_reason']) !== $draft['reason'])
            || ((isset($input['restock_refunded_items']) && $input['restock_refunded_items'] === 'true') !== $draft['restock_items'])) {
            throw new \RuntimeException('refund_full_native_input_required');
        }
        $qty = $this->inputMap($input, 'line_item_qtys');
        $totals = $this->inputMap($input, 'line_item_totals');
        $taxes = $this->inputMap($input, 'line_item_tax_totals');
        foreach ($taxes as $id => $values) {
            if (!isset($draft['line_items'][$id]) || !is_array($values)
                || count($values) !== count($draft['line_items'][$id]['refund_tax'])) {
                throw new \RuntimeException('refund_request_conflict');
            }
        }
        foreach ($draft['line_items'] as $id => $item) {
            if (!isset($qty[$id], $totals[$id]) || !AmountGuard::amountsEqual((string) $qty[$id], $item['qty'])
                || !AmountGuard::amountsEqual((string) $totals[$id], $item['refund_total'])
                || ($item['refund_tax'] && (!isset($taxes[$id]) || $taxes[$id] != $item['refund_tax']))) {
                throw new \RuntimeException('refund_request_conflict');
            }
        }
        if (count($qty) !== count($draft['line_items']) || count($totals) !== count($draft['line_items'])) {
            throw new \RuntimeException('refund_request_conflict');
        }
    }

    private function inputMap(array $input, $key): array
    {
        if (!isset($input[$key])) { return array(); }
        $value = is_string($input[$key]) ? json_decode($input[$key], true) : $input[$key];
        if (!is_array($value)) { throw new \RuntimeException('refund_native_items_invalid'); }
        return $value;
    }

    private function snapshot($order): string
    {
        $items = array();
        foreach ($order->get_items(array('line_item', 'fee', 'shipping')) as $id => $item) {
            $items[$id] = array((string) $item->get_quantity(), (string) $item->get_total(), $item->get_taxes());
        }
        return hash('sha256', json_encode(array((string) $order->get_total(), $order->get_currency(), $items), JSON_UNESCAPED_SLASHES));
    }

    private function matchesDraft($order, array $draft): bool
    {
        return $draft['snapshot'] === $this->snapshot($order) && $draft['currency'] === $order->get_currency()
            && AmountGuard::amountsEqual($draft['amount_decimal'], (string) $order->get_total());
    }

    public static function minor(string $decimal, int $scale): string
    {
        if ($scale < 0 || $scale > 18) { throw new \RuntimeException('refund_native_scale_invalid'); }
        $normal = AmountGuard::normalizeDecimal($decimal);
        if ($normal === null) { throw new \RuntimeException('refund_native_amount_invalid'); }
        $parts = explode('.', $normal);
        $fraction = isset($parts[1]) ? $parts[1] : '';
        if (!preg_match('/^[0-9]+$/D', $parts[0]) || strlen($fraction) > $scale) {
            throw new \RuntimeException('refund_native_amount_invalid');
        }
        $minor = ltrim($parts[0] . str_pad($fraction, $scale, '0'), '0');
        if ($minor === '') { throw new \RuntimeException('refund_native_amount_invalid'); }
        return $minor;
    }

    private function ambiguous(RefundIntent $intent, $reason): NativeProjectionResult
    {
        return new NativeProjectionResult(NativeProjectionResult::AMBIGUOUS, $intent->mode(), null, null, null, null, null, $reason);
    }
}
