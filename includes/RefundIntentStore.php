<?php

declare(strict_types=1);

namespace PaymosWooCommerce;

use Paymos\Plugin\AmountGuard;
use Paymos\Plugin\EffectClaim;
use Paymos\Plugin\ExternalRefundObservation;
use Paymos\Plugin\NativeProjectionResult;
use Paymos\Plugin\RefundIntent;
use Paymos\Plugin\RefundIntentPage;
use Paymos\Plugin\RefundIntentStoreInterface;
use Paymos\Plugin\RefundStates;
use Paymos\Plugin\VerifiedRefund;
use Paymos\Plugin\WordPressOrderLock;

defined('ABSPATH') || exit;

final class RefundIntentStore implements RefundIntentStoreInterface
{
    public const SCHEMA_OPTION = 'paymos_woocommerce_refund_schema';
    private $lock;
    private $paymentKey;
    private $transactionDepth = 0;

    public static function tableName()
    {
        global $wpdb;
        return $wpdb->prefix . 'paymos_refund_intents';
    }

    public static function install()
    {
        global $wpdb;
        if (get_option(self::SCHEMA_OPTION, '') === '1') {
            return;
        }
        $table = self::tableName();
        $collate = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE IF NOT EXISTS {$table} (
            operation_id varchar(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            installation_id varchar(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            request_token varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            external_order_id varchar(200) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            native_payment_key varchar(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            withdrawal_id varchar(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
            payload longtext NOT NULL, version bigint unsigned NOT NULL DEFAULT 1,
            created_at bigint unsigned NOT NULL, next_check bigint unsigned NOT NULL,
            PRIMARY KEY (operation_id), UNIQUE KEY request_token (installation_id,request_token),
            UNIQUE KEY external_order_id (external_order_id), UNIQUE KEY withdrawal_id (withdrawal_id),
            KEY payment_key (native_payment_key), KEY due (next_check,operation_id)
        ) ENGINE=InnoDB {$collate};";
        if ($wpdb->query($sql) === false) {
            throw new \RuntimeException('refund_schema_unavailable');
        }
        update_option(self::SCHEMA_OPTION, '1', false);
    }

    public function withPaymentLock(string $paymentKey, callable $action)
    {
        if (!preg_match('/^[1-9][0-9]*$/D', $paymentKey)) {
            throw new \RuntimeException('refund_payment_key_invalid');
        }
        if ($this->lock !== null) {
            if ($this->paymentKey !== $paymentKey) {
                throw new \RuntimeException('refund_lock_scope_conflict');
            }
            $this->assertOwned();
            return $action();
        }
        self::install();
        $this->lock = new WordPressOrderLock('woocommerce', $paymentKey);
        $this->paymentKey = $paymentKey;
        try {
            if (!$this->lock->acquire()) {
                throw new \Paymos\Exception\EventInProgressException('refund_payment_busy');
            }
            return $action();
        } finally {
            $this->lock->release();
            $this->lock = null;
            $this->paymentKey = null;
        }
    }

    public function assertOwned()
    {
        if ($this->lock === null) {
            throw new \RuntimeException('refund_payment_lock_required');
        }
        $this->lock->assertOwned();
    }

    public function loadOrder()
    {
        $this->assertOwned();
        return FreshOrder::load((int) $this->paymentKey, $this->lock);
    }

    public function loadNative($id)
    {
        $this->assertOwned();
        return FreshOrder::load((int) $id, $this->lock);
    }

    public function externalObservations(): array
    {
        $rows = $this->loadOrder()->get_meta('_paymos_external_refunds', true);
        if ($rows === '') { return array(); }
        if (!is_array($rows)) { throw new \RuntimeException('refund_external_history_corrupt'); }
        foreach ($rows as $id => $row) {
            if (!is_array($row)) { throw new \RuntimeException('refund_external_history_corrupt'); }
            $cash = ExternalRefundObservation::stored($row);
            if ($cash->withdrawalId() !== $id || $row['payment_key'] !== $this->paymentKey) {
                throw new \RuntimeException('refund_external_history_corrupt');
            }
        }
        return $rows;
    }

    public function assertNoExternalReview(): void
    {
        foreach ($this->externalObservations() as $row) {
            if (($row['review_reason'] ?? null) !== null) { throw new \RuntimeException('refund_unbound_requires_review'); }
        }
    }

    public function observeExternal(VerifiedRefund $refund): void
    {
        $this->transaction(function () use ($refund) {
            $order = $this->loadOrder();
            $rows = $this->externalObservations();
            $id = $refund->withdrawalId();
            $row = ExternalRefundObservation::fold($rows[$id] ?? null, $refund, $this->paymentKey);
            $marker = '[pmrf:external:' . $id . ']';
            $evidence = $marker . ' ' . AmountGuard::normalizeDecimal($refund->principalDecimal()) . ' ' . $refund->currency() . '/' . $refund->network();
            if ($row['note_id'] !== null) {
                $note = get_comment((int) $row['note_id']);
                if (!$note || (string) $note->comment_post_ID !== $this->paymentKey
                    || substr_count($note->comment_content, $evidence) !== 1) {
                    $row['review_reason'] = 'refund_external_note_conflict';
                }
            } elseif (ExternalRefundObservation::needsNote($row)) {
                global $wpdb;
                $native = $wpdb->get_results($wpdb->prepare('SELECT comment_ID FROM %i WHERE comment_post_ID = %d AND comment_type = %s AND LOCATE(%s, comment_content) > 0',
                    $wpdb->prefix . 'comments', (int) $this->paymentKey, 'order_note', $marker), ARRAY_A);
                if (!is_array($native) || $wpdb->last_error !== '') { throw new \RuntimeException('refund_external_note_read_failed'); }
                if (count($native) === 1) {
                    $note = get_comment((int) $native[0]['comment_ID']);
                    if (!$note || (string) $note->comment_post_ID !== $this->paymentKey || substr_count($note->comment_content, $evidence) !== 1) {
                        $row['review_reason'] = 'refund_external_note_conflict';
                    } else { $row['note_id'] = (string) $native[0]['comment_ID']; }
                } elseif (count($native) > 1) { $row['review_reason'] = 'refund_external_note_conflict'; }
                else {
                    $locale = function_exists('get_user_locale') ? get_user_locale() : 'en';
                    $noteId = $order->add_order_note(\Paymos\Plugin\RefundCopy::text('cash_note', $locale) . ' ' . $evidence, false);
                    if (!$noteId) { throw new \RuntimeException('refund_cash_note_failed'); }
                    $row['note_id'] = (string) $noteId;
                }
            }
            $rows[$id] = $row;
            $order->update_meta_data('_paymos_external_refunds', $rows);
            if (!$order->save()) { throw new \RuntimeException('refund_external_history_write_failed'); }
        });
    }

    public function assertTransactionalTables()
    {
        global $wpdb;
        $names = array('posts', 'postmeta', 'comments', 'commentmeta', 'options',
            'woocommerce_order_items', 'woocommerce_order_itemmeta', 'wc_product_meta_lookup', 'paymos_refund_intents');
        $hpos = 'Automattic\\WooCommerce\\Utilities\\OrderUtil';
        if (class_exists($hpos) && $hpos::custom_orders_table_usage_is_enabled()) {
            $names = array_merge($names, array('wc_orders', 'wc_orders_meta', 'wc_order_addresses', 'wc_order_operational_data'));
        }
        foreach ($names as $name) {
            $table = $wpdb->prefix . $name;
            $row = $wpdb->get_row($wpdb->prepare('SHOW TABLE STATUS WHERE Name = %s', $table), ARRAY_A);
            if (!is_array($row) || !isset($row['Engine']) || strcasecmp($row['Engine'], 'InnoDB') !== 0) {
                throw new \RuntimeException('refund_transactional_tables_required');
            }
        }
        foreach (array('woocommerce_', 'wc_', 'actionscheduler_') as $prefix) {
            $rows = $wpdb->get_results($wpdb->prepare('SHOW TABLE STATUS WHERE Name LIKE %s', $wpdb->esc_like($wpdb->prefix . $prefix) . '%'), ARRAY_A);
            if (!is_array($rows) || $wpdb->last_error !== '') { throw new \RuntimeException('refund_transactional_tables_required'); }
            foreach ($rows as $row) {
                if (!isset($row['Engine']) || strcasecmp($row['Engine'], 'InnoDB') !== 0) {
                    throw new \RuntimeException('refund_transactional_tables_required');
                }
            }
        }
    }

    public function transaction(callable $action)
    {
        global $wpdb;
        $this->assertOwned();
        if ($this->transactionDepth > 0) {
            return $action();
        }
        $this->assertTransactionalTables();
        wc_transaction_query('start', true);
        if ($wpdb->last_error !== '') {
            throw new \RuntimeException('refund_transaction_start_failed');
        }
        $this->transactionDepth++;
        try {
            $result = $action();
            $this->assertOwned();
            wc_transaction_query('commit', true);
            if ($wpdb->last_error !== '') {
                throw new \RuntimeException('refund_transaction_commit_unknown');
            }
            $this->assertOwned();
            return $result;
        } catch (\Throwable $error) {
            wc_transaction_query('rollback', true);
            throw $error;
        } finally {
            $this->transactionDepth--;
        }
    }

    public function assertNativeTransaction()
    {
        $this->assertOwned();
        if ($this->transactionDepth === 0) { throw new \RuntimeException('refund_native_transaction_required'); }
    }

    public function blocksNewIntent(RefundIntent $intent): bool
    {
        return $intent->projectionState() === RefundStates::NATIVE_REVIEW
            || ($intent->mode() === RefundStates::FULL && $intent->cashState() === RefundStates::COMPLETED)
            || !in_array($intent->cashState(), array(RefundStates::COMPLETED, RefundStates::FAILED, RefundStates::CANCELLED), true)
            || ($intent->cashState() === RefundStates::COMPLETED && $intent->projectionState() !== RefundStates::PROJECTION_APPLIED);
    }

    public function createIntent(RefundIntent $intent): RefundIntent
    {
        $this->assertIntentLock($intent);
        if ($this->transactionDepth !== 0) {
            throw new \RuntimeException('refund_intent_must_commit');
        }
        return $this->transaction(function () use ($intent) {
            global $wpdb;
            $data = $intent->toArray();
            $existing = $this->findByRequestToken($data['installation_id'], $data['request_token']);
            if ($existing !== null) {
                foreach (array('installation_id', 'request_token', 'platform', 'native_payment_key', 'invoice_id',
                    'project_id', 'environment', 'mode', 'full_draft') as $field) {
                    if ($existing->toArray()[$field] !== $data[$field]) {
                        throw new \RuntimeException('refund_request_conflict');
                    }
                }
                return $existing;
            }
            foreach ($this->forPayment($intent->paymentKey()) as $old) {
                if ($this->blocksNewIntent($old)) {
                    throw new \RuntimeException('refund_payment_has_unresolved_intent');
                }
            }
            $now = time();
            $data['created_at'] = $now;
            $data['checks'] = 0;
            $data['version'] = 1;
            $inserted = $wpdb->insert(self::tableName(), array(
                'operation_id' => $intent->operationId(), 'installation_id' => $data['installation_id'],
                'request_token' => $data['request_token'], 'external_order_id' => $intent->externalOrderId(),
                'native_payment_key' => $intent->paymentKey(), 'withdrawal_id' => null,
                'payload' => $this->encode($data), 'version' => 1, 'created_at' => $now, 'next_check' => $now + 60,
            ));
            if ($inserted !== 1) {
                throw new \RuntimeException('refund_request_conflict');
            }
            return new RefundIntent($data);
        });
    }

    public function findByRequestToken(string $installationId, string $token): ?RefundIntent
    {
        global $wpdb;
        return $this->row($wpdb->prepare('SELECT payload FROM %i WHERE installation_id = %s AND request_token = %s', self::tableName(), $installationId, $token));
    }

    public function getIntent(string $operationId): RefundIntent
    {
        $intent = $this->find('operation_id', $operationId);
        if ($intent === null) { throw new \RuntimeException('refund_intent_missing'); }
        return $intent;
    }

    public function findByExternalId(string $key): ?RefundIntent { return $this->find('external_order_id', $key); }
    public function findByWithdrawalId(string $id): ?RefundIntent { return $this->find('withdrawal_id', $id); }

    public function forPayment(string $paymentKey): array
    {
        global $wpdb;
        return $this->rows($wpdb->prepare('SELECT payload FROM %i WHERE native_payment_key = %s ORDER BY created_at, operation_id', self::tableName(), $paymentKey));
    }

    public function bindWithdrawal(string $operationId, string $id): string
    {
        return $this->transaction(function () use ($operationId, $id) {
            $intent = $this->getIntent($operationId);
            $this->assertIntentLock($intent);
            $other = $this->findByWithdrawalId($id);
            if ($id === '' || ($other !== null && $other->operationId() !== $operationId)
                || ($intent->withdrawalId() !== null && $intent->withdrawalId() !== $id)) {
                $this->recordState($operationId, RefundStates::REVIEW, RefundStates::NATIVE_REVIEW, 'refund_binding_conflict');
                return 'conflict';
            }
            if ($intent->withdrawalId() === $id) { return 'same'; }
            $this->save($intent, array('withdrawal_id' => $id));
            return 'bound';
        });
    }

    public function recordObservation(string $operationId, VerifiedRefund $snapshot): void
    {
        $this->transaction(function () use ($operationId, $snapshot) {
            $intent = $this->getIntent($operationId);
            $pin = $intent->observation();
            if ($intent->withdrawalId() !== $snapshot->withdrawalId() || $intent->invoiceId() !== $snapshot->invoiceId()
                || $intent->projectId() !== $snapshot->projectId() || $intent->environment() !== $snapshot->environment()
                || $intent->mode() !== $snapshot->mode() || $intent->externalOrderId() !== $snapshot->externalOrderId()
                || ($pin !== null && (!AmountGuard::amountsEqual($pin->principalDecimal(), $snapshot->principalDecimal())
                    || $pin->currency() !== $snapshot->currency() || $pin->network() !== $snapshot->network()
                    || !\Paymos\Plugin\RefundRecipientIdentity::same($pin, $snapshot)))) {
                throw new \RuntimeException('refund_observation_conflict');
            }
            $this->save($intent, array('observation' => $snapshot->toArray()));
        });
    }

    public function recordProjection(string $operationId, NativeProjectionResult $result): void
    {
        $this->transaction(function () use ($operationId, $result) {
            $intent = $this->getIntent($operationId);
            if ($result->mode() !== $intent->mode() || $result->kind() !== NativeProjectionResult::EXACT_MATCH) {
                throw new \RuntimeException('refund_projection_conflict');
            }
            $draft = $intent->fullDraft();
            $snapshot = $intent->observation();
            if ($result->nativeId() === null || $result->nativeId() === '' || $snapshot === null || $snapshot->status() !== 'completed'
                || ($intent->toArray()['projection'] !== null && $intent->toArray()['projection']['nativeId'] !== $result->nativeId())
                || ($draft !== null && ($result->nativeAmountMinor() !== $draft['amount_minor'] || $result->nativeCurrency() !== $draft['currency']))
                || ($draft === null && ($result->nativeAmountMinor() !== null || $result->nativeCurrency() !== null
                    || !AmountGuard::amountsEqual($result->cryptoObservationAmount(), $snapshot->principalDecimal())
                    || $result->cryptoAsset() !== $snapshot->currency() . '/' . $snapshot->network()))) {
                throw new \RuntimeException('refund_projection_conflict');
            }
            $this->save($intent, array('projection' => $result->toArray()));
        });
    }

    public function recordState(string $operationId, string $cashState, string $projectionState, ?string $reason): void
    {
        if (!in_array($cashState, array('not_submitted', 'unknown', 'created', 'processing', 'completed', 'failed', 'cancelled', 'review'), true)
            || !in_array($projectionState, array('none', 'pending', 'applied', 'native_review'), true)) {
            throw new \InvalidArgumentException('refund_state_invalid');
        }
        $this->transaction(function () use ($operationId, $cashState, $projectionState, $reason) {
            $this->save($this->getIntent($operationId), array('cash_state' => $cashState,
                'projection_state' => $projectionState, 'review_reason' => $reason));
        });
    }

    public function claimEffect(string $operationId, string $kind, int $generation): EffectClaim
    {
        if ($kind === '' || $generation < 1) { throw new \InvalidArgumentException('refund_effect_invalid'); }
        if ($this->transactionDepth !== 0) { throw new \RuntimeException('refund_effect_must_commit'); }
        return $this->transaction(function () use ($operationId, $kind, $generation) {
            $intent = $this->getIntent($operationId);
            $effects = $intent->toArray()['effects'];
            $key = $kind . ':' . $generation;
            if (isset($effects[$key]) && $effects[$key] !== RefundStates::EFFECT_PENDING) {
                return new EffectClaim(EffectClaim::ALREADY_STARTED);
            }
            $effects[$key] = RefundStates::EFFECT_STARTED;
            $this->save($intent, array('effects' => $effects));
            return new EffectClaim(EffectClaim::CLAIMED);
        });
    }

    public function recordEffectOutcome(string $operationId, string $kind, int $generation, string $state): void
    {
        if ($kind === '' || $generation < 1 || !in_array($state, array('pending', 'started', 'delivered', 'failed_no_effect', 'unknown', 'skipped'), true)) {
            throw new \InvalidArgumentException('refund_effect_invalid');
        }
        $this->transaction(function () use ($operationId, $kind, $generation, $state) {
            $intent = $this->getIntent($operationId);
            $effects = $intent->toArray()['effects'];
            $effects[$kind . ':' . $generation] = $state;
            $this->save($intent, array('effects' => $effects));
        });
    }

    public function listDue(\DateTimeImmutable $now, int $limit, ?string $cursor): RefundIntentPage
    {
        global $wpdb;
        self::install();
        $limit = max(1, min(100, $limit));
        $items = $this->rows($wpdb->prepare('SELECT payload FROM %i WHERE next_check <= %d AND operation_id > %s ORDER BY operation_id LIMIT %d',
            self::tableName(), $now->getTimestamp(), $cursor ?: '', $limit));
        return new RefundIntentPage($items, count($items) === $limit ? end($items)->operationId() : null);
    }

    public function defer(string $operationId): void
    {
        $this->transaction(function () use ($operationId) {
            $intent = $this->getIntent($operationId);
            $data = $intent->toArray();
            $checks = $data['checks'] + 1;
            $historical = in_array($intent->cashState(), array(RefundStates::COMPLETED, RefundStates::FAILED, RefundStates::CANCELLED, RefundStates::REVIEW), true);
            $delays = array(60, 120, 300, 900, 3600);
            if (!$historical && time() - $data['created_at'] > 86400) {
                $data['review_reason'] = 'refund_unresolved_over_24h';
                $data['projection_state'] = RefundStates::NATIVE_REVIEW;
                $historical = true;
            }
            $this->save($intent, array('checks' => $checks, 'cash_state' => $data['cash_state'],
                'projection_state' => $data['projection_state'], 'review_reason' => $data['review_reason']),
                time() + ($historical ? 86400 : $delays[min($checks, 4)]));
        });
    }

    private function find($column, $value): ?RefundIntent
    {
        global $wpdb;
        return $this->row($wpdb->prepare('SELECT payload FROM %i WHERE %i = %s', self::tableName(), $column, $value));
    }

    private function row($sql): ?RefundIntent
    {
        global $wpdb;
        $row = $wpdb->get_row($sql, ARRAY_A);
        if ($wpdb->last_error !== '') { throw new \RuntimeException('refund_store_read_failed'); }
        return is_array($row) ? $this->decode($row['payload']) : null;
    }

    private function rows($sql): array
    {
        global $wpdb;
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows) || $wpdb->last_error !== '') { throw new \RuntimeException('refund_store_read_failed'); }
        return array_map(function ($row) { return $this->decode($row['payload']); }, $rows);
    }

    private function decode($payload): RefundIntent
    {
        $data = json_decode($payload, true);
        if (!is_array($data)) { throw new \RuntimeException('refund_store_corrupt'); }
        return new RefundIntent($data);
    }

    private function encode(array $data): string
    {
        $json = json_encode($data, JSON_UNESCAPED_SLASHES);
        if ($json === false) { throw new \RuntimeException('refund_store_encoding_failed'); }
        return $json;
    }

    private function assertIntentLock(RefundIntent $intent)
    {
        $this->assertOwned();
        if ($this->paymentKey !== $intent->paymentKey()) { throw new \RuntimeException('refund_lock_scope_conflict'); }
    }

    private function save(RefundIntent $intent, array $changes, $nextCheck = null)
    {
        global $wpdb;
        $this->assertIntentLock($intent);
        $old = $intent->toArray();
        $data = array_replace($old, $changes, array('version' => $old['version'] + 1));
        $columns = array('payload' => $this->encode($data), 'withdrawal_id' => $data['withdrawal_id'], 'version' => $data['version']);
        if ($nextCheck !== null) { $columns['next_check'] = $nextCheck; }
        if ($wpdb->update(self::tableName(), $columns, array('operation_id' => $intent->operationId(), 'version' => $old['version'])) !== 1) {
            throw new \RuntimeException('refund_store_write_conflict');
        }
    }
}
