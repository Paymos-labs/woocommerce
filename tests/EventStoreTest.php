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

    public function prepare($query, $value)
    {
        return array('query' => $query, 'value' => $value);
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
