<?php

declare(strict_types=1);

namespace PaymosWooCommerce;

use Paymos\Plugin\WordPressEncryptedOption;
use Paymos\Plugin\WordPressOrderLock;

defined('ABSPATH') || exit;

final class ConnectStateStore
{
    public const OPTION_KEY = 'paymos_woocommerce_connect_state_v1';
    private const AAD = 'paymos-for-woocommerce-connect-state-v1';

    public static function save(array $state)
    {
        if (!isset($state['device_code'], $state['expires_in'], $state['started_at'])) {
            throw new \InvalidArgumentException('Paymos connection state is incomplete.');
        }
        $state['expires_at'] = (int) $state['started_at'] + (int) $state['expires_in'];
        $state['flow_id'] = bin2hex(random_bytes(16));
        return self::locked(static function () use ($state) {
            WordPressEncryptedOption::save(self::OPTION_KEY, self::AAD, array('schema' => 1, 'state' => $state));
            return $state['flow_id'];
        });
    }

    public static function load()
    {
        return self::locked(static function () { return self::read(); });
    }

    private static function read()
    {
        $payload = WordPressEncryptedOption::load(self::OPTION_KEY, self::AAD);
        if (count($payload) === 0) {
            return array();
        }
        if (!isset($payload['schema'], $payload['state'])
            || (int) $payload['schema'] !== 1
            || !is_array($payload['state'])) {
            throw new \RuntimeException('Stored Paymos connection state has an invalid schema.');
        }
        $state = $payload['state'];
        if (!isset($state['expires_at']) || time() >= (int) $state['expires_at']) {
            WordPressEncryptedOption::delete(self::OPTION_KEY);
            return array();
        }
        return $state;
    }

    public static function clear($flowId)
    {
        return self::finish($flowId, null);
    }

    public static function complete($flowId, array $credentials)
    {
        return self::finish($flowId, $credentials);
    }

    private static function finish($flowId, $credentials)
    {
        return self::locked(static function () use ($flowId, $credentials) {
            $state = self::read();
            if (!is_string($flowId) || $flowId === '' || !isset($state['flow_id'])
                || !hash_equals($state['flow_id'], $flowId)) {
                return false;
            }
            if ($credentials !== null) {
                CredentialStore::save($credentials);
            }
            WordPressEncryptedOption::delete(self::OPTION_KEY);
            return true;
        });
    }

    private static function locked(callable $operation)
    {
        $lock = new WordPressOrderLock('woocommerce-connect', self::OPTION_KEY);
        if (!$lock->acquire()) {
            throw new \RuntimeException('Paymos connection state is busy.');
        }
        try {
            // A previous poll may have cached the old option before a new start.
            wp_cache_delete(self::OPTION_KEY, 'options');
            wp_cache_delete('alloptions', 'options');
            wp_cache_delete('notoptions', 'options');
            $lock->assertOwned();
            return $operation();
        } finally {
            $lock->release();
        }
    }
}
