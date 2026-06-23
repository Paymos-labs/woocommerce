<?php

declare(strict_types=1);

namespace PaymosWooCommerce;

use Paymos\Webhook\EventStoreInterface;

defined('ABSPATH') || exit;

final class EventStore implements EventStoreInterface
{
    /** @var string */
    private $pendingKey = '';

    /** @var string */
    private $pendingHash = '';

    /** @var int */
    private $pendingTtlSeconds = 0;

    /** @var bool|null */
    private static $tableReady;

    public static function install()
    {
        global $wpdb;

        if (!self::canUseDatabase()) {
            return;
        }

        $charsetCollate = method_exists($wpdb, 'get_charset_collate')
            ? $wpdb->get_charset_collate()
            : '';
        $table = self::tableName();

        $created = $wpdb->query(
            "CREATE TABLE IF NOT EXISTS {$table} (
                event_hash varchar(32) NOT NULL,
                event_id varchar(191) NOT NULL,
                status varchar(16) NOT NULL,
                expires_at bigint unsigned NOT NULL,
                created_at bigint unsigned NOT NULL,
                updated_at bigint unsigned NOT NULL,
                PRIMARY KEY (event_hash),
                KEY expires_at (expires_at)
            ) {$charsetCollate};"
        );

        self::$tableReady = $created !== false;
    }

    public function remember($eventId, $ttlSeconds)
    {
        if ($this->rememberInDatabase($eventId, $ttlSeconds)) {
            return true;
        }
        if (self::canUseDatabase() && self::$tableReady === true) {
            return false;
        }

        $key = 'paymos_evt_' . md5((string) $eventId);
        $lockKey = $key . '_lock';
        if (get_transient($key)) {
            return false;
        }
        if (get_transient($lockKey)) {
            return false;
        }

        $this->pendingKey = $key;
        $this->pendingHash = '';
        $this->pendingTtlSeconds = (int) $ttlSeconds;

        if (function_exists('add_option')) {
            $timeout = '_transient_timeout_' . $lockKey;
            $value = '_transient_' . $lockKey;
            add_option($timeout, (string) (time() + 300), '', 'no');

            return add_option($value, '1', '', 'no');
        }

        set_transient($lockKey, '1', 300);
        return true;
    }

    public function commit()
    {
        if ($this->pendingHash !== '') {
            $this->commitDatabase();
            return;
        }

        if ($this->pendingKey === '') {
            return;
        }

        set_transient($this->pendingKey, '1', $this->pendingTtlSeconds);
        $this->release();
    }

    public function release()
    {
        if ($this->pendingHash !== '') {
            $this->releaseDatabase();
            return;
        }

        if ($this->pendingKey === '') {
            return;
        }

        $lockKey = $this->pendingKey . '_lock';
        if (function_exists('delete_option')) {
            delete_option('_transient_timeout_' . $lockKey);
            delete_option('_transient_' . $lockKey);
        }

        if (function_exists('delete_transient')) {
            delete_transient($lockKey);
        }

        $this->pendingKey = '';
        $this->pendingTtlSeconds = 0;
    }

    private function rememberInDatabase($eventId, $ttlSeconds)
    {
        global $wpdb;

        if (!self::ensureTable()) {
            return false;
        }

        $hash = md5((string) $eventId);
        $now = time();
        $lockExpiresAt = $now + 300;

        $inserted = $wpdb->insert(
            self::tableName(),
            array(
                'event_hash' => $hash,
                'event_id' => (string) $eventId,
                'status' => 'processing',
                'expires_at' => $lockExpiresAt,
                'created_at' => $now,
                'updated_at' => $now,
            ),
            array('%s', '%s', '%s', '%d', '%d', '%d')
        );

        if ($inserted !== false) {
            $this->pendingHash = $hash;
            $this->pendingKey = '';
            $this->pendingTtlSeconds = (int) $ttlSeconds;
            return true;
        }

        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT status, expires_at FROM ' . self::tableName() . ' WHERE event_hash = %s',
            $hash
        ), ARRAY_A);

        if (!is_array($row)) {
            return false;
        }

        $expiresAt = isset($row['expires_at']) ? (int) $row['expires_at'] : 0;
        if ($expiresAt > 0 && $expiresAt <= $now) {
            $wpdb->delete(self::tableName(), array('event_hash' => $hash), array('%s'));
            return $this->rememberInDatabase($eventId, $ttlSeconds);
        }

        return false;
    }

    private function commitDatabase()
    {
        global $wpdb;

        $hash = $this->pendingHash;
        if ($hash === '' || !self::canUseDatabase()) {
            $this->pendingHash = '';
            $this->pendingTtlSeconds = 0;
            return;
        }

        $now = time();
        $wpdb->update(
            self::tableName(),
            array(
                'status' => 'committed',
                'expires_at' => $now + $this->pendingTtlSeconds,
                'updated_at' => $now,
            ),
            array(
                'event_hash' => $hash,
                'status' => 'processing',
            ),
            array('%s', '%d', '%d'),
            array('%s', '%s')
        );

        $this->pendingHash = '';
        $this->pendingTtlSeconds = 0;
    }

    private function releaseDatabase()
    {
        global $wpdb;

        $hash = $this->pendingHash;
        if ($hash !== '' && self::canUseDatabase()) {
            $wpdb->delete(
                self::tableName(),
                array('event_hash' => $hash, 'status' => 'processing'),
                array('%s', '%s')
            );
        }

        $this->pendingHash = '';
        $this->pendingTtlSeconds = 0;
    }

    private static function ensureTable()
    {
        if (!self::canUseDatabase()) {
            return false;
        }
        if (self::$tableReady === true) {
            return true;
        }

        self::install();
        return self::$tableReady === true;
    }

    private static function canUseDatabase()
    {
        global $wpdb;

        return is_object($wpdb)
            && method_exists($wpdb, 'query')
            && method_exists($wpdb, 'insert')
            && method_exists($wpdb, 'get_row')
            && method_exists($wpdb, 'prepare')
            && method_exists($wpdb, 'delete')
            && method_exists($wpdb, 'update');
    }

    private static function tableName()
    {
        global $wpdb;

        $prefix = is_object($wpdb) && isset($wpdb->prefix) ? (string) $wpdb->prefix : '';
        return $prefix . 'paymos_webhook_events';
    }
}
