<?php

declare(strict_types=1);

namespace PaymosWooCommerce;

use Paymos\Webhook\CommitAwareEventStoreInterface;

defined('ABSPATH') || exit;

final class EventStore implements CommitAwareEventStoreInterface
{
    public const SCHEMA_OPTION = 'paymos_woocommerce_event_schema';
    private $pendingHash = '';
    private $pendingOwner = '';
    private $pendingTtlSeconds = 0;

    public static function install()
    {
        global $wpdb;
        self::requireDatabase();
        if (get_option(self::SCHEMA_OPTION, '') === '2') {
            return;
        }
        $table = self::tableName();
        $collate = method_exists($wpdb, 'get_charset_collate') ? $wpdb->get_charset_collate() : '';
        // phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Dedicated atomic event table; identifier comes only from the trusted wpdb prefix.
        $created = $wpdb->query("CREATE TABLE IF NOT EXISTS {$table} (
            event_hash varchar(32) NOT NULL, event_id varchar(191) NOT NULL,
            status varchar(16) NOT NULL, owner_token char(32) NOT NULL DEFAULT '',
            expires_at bigint unsigned NOT NULL, created_at bigint unsigned NOT NULL,
            updated_at bigint unsigned NOT NULL, PRIMARY KEY (event_hash), KEY expires_at (expires_at)
        ) {$collate};");
        // phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        if ($created === false) {
            throw new \RuntimeException('Paymos event table is unavailable.');
        }
        if (get_option(self::SCHEMA_OPTION, '') !== '2') {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Versioned schema inspection, not cached payment state.
            $column = $wpdb->get_row($wpdb->prepare('SHOW COLUMNS FROM %i LIKE %s', $table, 'owner_token'), ARRAY_A);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Identifier is exclusively the trusted wpdb prefix; no request data is interpolated.
            if (!is_array($column) && $wpdb->query("ALTER TABLE {$table} ADD owner_token char(32) NOT NULL DEFAULT ''") === false) {
                throw new \RuntimeException('Paymos event schema upgrade failed.');
            }
            update_option(self::SCHEMA_OPTION, '2', false);
        }
    }

    public static function purgeExpired($exceptHash = '')
    {
        global $wpdb;
        self::requireDatabase();
        // All expired rows are bounded; the current target uses CAS takeover below. Owner fencing rejects crashed predecessors.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Bounded expiry maintenance, with prepared identifiers and values.
        if ($wpdb->query($wpdb->prepare('DELETE FROM %i WHERE expires_at <= %d AND event_hash <> %s ORDER BY expires_at ASC LIMIT 100', self::tableName(), time(), (string) $exceptHash)) === false) {
            throw new \RuntimeException('Paymos event retention failed.');
        }
    }

    public function remember($eventId, $ttlSeconds)
    {
        global $wpdb;
        self::install();
        $hash = md5((string) $eventId);
        self::purgeExpired($hash);
        $now = time();
        $owner = bin2hex(random_bytes(16));
        $data = array('event_hash' => $hash, 'event_id' => (string) $eventId, 'status' => 'processing', 'owner_token' => $owner, 'expires_at' => $now + 300, 'created_at' => $now, 'updated_at' => $now);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Unique key performs atomic acquisition.
        $inserted = $wpdb->insert(self::tableName(), $data, array('%s', '%s', '%s', '%s', '%d', '%d', '%d'));
        if ($inserted !== 1) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Uncached lease state is required for CAS.
            $row = $wpdb->get_row($wpdb->prepare('SELECT status, expires_at, owner_token FROM %i WHERE event_hash = %s', self::tableName(), $hash), ARRAY_A);
            if (!is_array($row)) {
                throw new \RuntimeException('Paymos event reservation failed.');
            }
            if ((int) $row['expires_at'] > $now) {
                return false;
            }
            // Every observed version field participates in the compare-and-swap. No expired DELETE/INSERT gap.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Atomic expired-owner takeover.
            $changed = $wpdb->update(self::tableName(), $data, array('event_hash' => $hash, 'status' => (string) $row['status'], 'expires_at' => (int) $row['expires_at'], 'owner_token' => isset($row['owner_token']) ? (string) $row['owner_token'] : ''), array('%s', '%s', '%s', '%s', '%d', '%d', '%d'), array('%s', '%s', '%d', '%s'));
            if ($changed === false) {
                throw new \RuntimeException('Paymos event takeover failed.');
            }
            if ($changed !== 1) {
                return false;
            }
        }
        $this->pendingHash = $hash;
        $this->pendingOwner = $owner;
        $this->pendingTtlSeconds = max(1, (int) $ttlSeconds);
        return true;
    }

    public function isCommitted($eventId)
    {
        global $wpdb;
        self::requireDatabase();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Never cache deduplication state.
        $row = $wpdb->get_row($wpdb->prepare('SELECT status, expires_at FROM %i WHERE event_hash = %s', self::tableName(), md5((string) $eventId)), ARRAY_A);
        return is_array($row) && $row['status'] === 'committed' && (int) $row['expires_at'] > time();
    }

    public function commit()
    {
        global $wpdb;
        if ($this->pendingHash === '') {
            return;
        }
        self::requireDatabase();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Fenced commit, including owner and processing state.
        $changed = $wpdb->update(self::tableName(), array('status' => 'committed', 'expires_at' => time() + $this->pendingTtlSeconds, 'updated_at' => time()), array('event_hash' => $this->pendingHash, 'status' => 'processing', 'owner_token' => $this->pendingOwner), array('%s', '%d', '%d'), array('%s', '%s', '%s'));
        if ($changed !== 1) {
            throw new \RuntimeException('Paymos event reservation ownership was lost.');
        }
        $this->clear();
    }

    public function release()
    {
        global $wpdb;
        if ($this->pendingHash === '') {
            return;
        }
        self::requireDatabase();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- A stale owner must never delete a successor lease.
        $deleted = $wpdb->delete(self::tableName(), array('event_hash' => $this->pendingHash, 'status' => 'processing', 'owner_token' => $this->pendingOwner), array('%s', '%s', '%s'));
        $this->clear();
        if ($deleted === false) {
            throw new \RuntimeException('Paymos event reservation release failed.');
        }
    }

    private function clear()
    {
        $this->pendingHash = '';
        $this->pendingOwner = '';
        $this->pendingTtlSeconds = 0;
    }

    private static function requireDatabase()
    {
        global $wpdb;
        foreach (array('query', 'insert', 'get_row', 'prepare', 'delete', 'update') as $method) {
            if (!is_object($wpdb) || !method_exists($wpdb, $method)) {
                throw new \RuntimeException('Paymos requires an available database for event reservations.');
            }
        }
    }

    private static function tableName()
    {
        global $wpdb;
        return $wpdb->prefix . 'paymos_webhook_events';
    }
}
