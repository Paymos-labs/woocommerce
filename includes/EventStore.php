<?php

declare(strict_types=1);

namespace PaymosWooCommerce;

use Paymos\Webhook\EventStoreInterface;

defined('ABSPATH') || exit;

final class EventStore implements EventStoreInterface
{
    /** @var string */
    private $pendingKey = '';

    /** @var int */
    private $pendingTtlSeconds = 0;

    public function remember($eventId, $ttlSeconds)
    {
        $key = 'paymos_evt_' . md5((string) $eventId);
        $lockKey = $key . '_lock';
        if (get_transient($key)) {
            return false;
        }
        if (get_transient($lockKey)) {
            return false;
        }

        $this->pendingKey = $key;
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
        if ($this->pendingKey === '') {
            return;
        }

        set_transient($this->pendingKey, '1', $this->pendingTtlSeconds);
        $this->release();
    }

    public function release()
    {
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
}
