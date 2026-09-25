<?php

declare(strict_types=1);

use PaymosWooCommerce\EventStore;

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

final class FakeWpdb
{
    /** @var string */
    public $prefix = 'wp_';

    /** @var array<string, array<string, mixed>> */
    public $rows = array();

    public function query($sql)
    {
        return true;
    }

    public function get_charset_collate()
    {
        return 'DEFAULT CHARSET=utf8mb4';
    }

    public function insert($table, array $data, array $format = array())
    {
        $hash = (string) $data['event_hash'];
        if (isset($this->rows[$hash])) {
            return false;
        }

        $this->rows[$hash] = $data;
        return 1;
    }

    public function prepare($query, ...$args)
    {
        // The event hash is always the last placeholder (a %i table name may
        // come first), which is what get_row() keys on.
        return array('query' => $query, 'value' => end($args));
    }

    public function get_row($prepared, $output = null)
    {
        $hash = is_array($prepared) && isset($prepared['value']) ? (string) $prepared['value'] : '';
        return isset($this->rows[$hash]) ? $this->rows[$hash] : null;
    }

    public function delete($table, array $where, array $whereFormat = array())
    {
        $hash = (string) $where['event_hash'];
        if (!isset($this->rows[$hash])) {
            return 0;
        }
        if (isset($where['status']) && $this->rows[$hash]['status'] !== $where['status']) {
            return 0;
        }

        unset($this->rows[$hash]);
        return 1;
    }

    public function update($table, array $data, array $where, array $format = array(), array $whereFormat = array())
    {
        $hash = (string) $where['event_hash'];
        if (!isset($this->rows[$hash])) {
            return 0;
        }
        if (isset($where['status']) && $this->rows[$hash]['status'] !== $where['status']) {
            return 0;
        }

        $this->rows[$hash] = array_merge($this->rows[$hash], $data);
        return 1;
    }
}

function test_event_store_commits_event_id_in_database()
{
    global $wpdb;

    $wpdb = new FakeWpdb();
    $store = new EventStore();

    assertSameValue(true, $store->remember('evt_db_commit', 3600), 'first event remember must acquire database lock.');
    $store->commit();

    $second = new EventStore();
    assertSameValue(false, $second->remember('evt_db_commit', 3600), 'committed event id must deduplicate future deliveries.');

    $hash = md5('evt_db_commit');
    assertSameValue('committed', $wpdb->rows[$hash]['status'], 'commit must persist event as committed.');

    $wpdb = null;
}

function test_event_store_release_allows_retry_in_database()
{
    global $wpdb;

    $wpdb = new FakeWpdb();
    $store = new EventStore();

    assertSameValue(true, $store->remember('evt_db_retry', 3600), 'first event remember must acquire database lock.');
    $store->release();

    $retry = new EventStore();
    assertSameValue(true, $retry->remember('evt_db_retry', 3600), 'released processing event must be retryable.');

    $wpdb = null;
}

function test_event_store_tells_a_locked_event_from_a_committed_one_in_database()
{
    // BUG-103: remember() answers false for both. The verifier asks
    // isCommitted() to decide between "duplicate" (2xx) and "in progress".
    global $wpdb;

    $wpdb = new FakeWpdb();
    $first = new EventStore();
    assertSameValue(true, $first->remember('evt_db_inflight', 3600), 'first delivery takes the lock.');

    $retry = new EventStore();
    assertSameValue(false, $retry->remember('evt_db_inflight', 3600), 'a retry while the lock is held is not new.');
    assertSameValue(false, $retry->isCommitted('evt_db_inflight'), 'a locked, uncommitted event is not committed.');

    $first->commit();
    assertSameValue(true, $retry->isCommitted('evt_db_inflight'), 'after commit the event is committed.');

    $wpdb = null;
}

function test_event_store_tells_a_locked_event_from_a_committed_one_in_transients()
{
    global $wpdb;

    paymos_reset_test_state();
    $wpdb = null;
    $store = new EventStore();
    $key = 'paymos_evt_' . md5('evt_transient_inflight');
    set_transient($key . '_lock', '1', 300);
    assertSameValue(false, $store->isCommitted('evt_transient_inflight'), 'only a lock: not committed.');

    set_transient($key, '1', 3600);
    assertSameValue(true, $store->isCommitted('evt_transient_inflight'), 'the committed marker: committed.');
}
