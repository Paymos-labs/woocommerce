<?php

declare(strict_types=1);

use PaymosWooCommerce\EventStore;

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

class FakeWpdb
{
    /** @var string */
    public $prefix = 'wp_';
    public $posts = 'wp_posts';
    public $options = 'wp_options';
    public function esc_like($value) { return addcslashes($value, '_%\\'); }
    public $locks = array();
    public $connectionId = '1';
    public function get_var($sql) {
        if (is_string($sql) && $sql === 'SELECT CONNECTION_ID()') { return $this->connectionId; }
        $q = $sql['query']; $a = $sql['args'];
        if (strpos($q, 'SELECT CASE GET_LOCK') === 0) { if (isset($this->locks[$a[0]])) { return '0'; } $this->locks[$a[0]] = $this->connectionId; return $this->connectionId; }
        if (strpos($q, 'SELECT IF(CONNECTION_ID()') === 0) {
            $owned = $a[0] === $this->connectionId && isset($this->locks[$a[1]]) && $this->locks[$a[1]] === $a[2];
            if ($owned && strpos($q, 'RELEASE_LOCK') !== false) { unset($this->locks[$a[1]]); }
            return $owned ? '1' : '0';
        }
        return null;
    }

    /** @var array<string, array<string, mixed>> */
    public $rows = array();
    public $queries = array();
    public $beforeUpdate = null;

    public function query($sql)
    {
        $this->queries[] = $sql;
        if (is_array($sql) && strpos($sql['query'], 'DELETE FROM') === 0 && strpos($sql['query'], 'expires_at') !== false) {
            $remaining = 100;
            foreach ($this->rows as $hash => $row) {
                if ($remaining > 0 && $row['expires_at'] <= $sql['args'][1] && $hash !== $sql['args'][2]) { unset($this->rows[$hash]); $remaining--; }
            }
        }
        return 1;
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
        if (strpos($query, ' AND ') === 0) { return ' AND `' . $args[0] . '`.id > ' . (int) $args[1]; }
        // The event hash is always the last placeholder (a %i table name may
        // come first), which is what get_row() keys on.
        return array('query' => $query, 'value' => end($args), 'args' => $args);
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
        foreach ($where as $key => $value) {
            if (!array_key_exists($key, $this->rows[$hash]) || $this->rows[$hash][$key] !== $value) { return 0; }
        }

        unset($this->rows[$hash]);
        return 1;
    }

    public function update($table, array $data, array $where, array $format = array(), array $whereFormat = array())
    {
        if ($this->beforeUpdate !== null) { $callback = $this->beforeUpdate; $this->beforeUpdate = null; $callback(); }
        $hash = (string) $where['event_hash'];
        if (!isset($this->rows[$hash])) {
            return 0;
        }
        foreach ($where as $key => $value) {
            if (!array_key_exists($key, $this->rows[$hash]) || $this->rows[$hash][$key] !== $value) { return 0; }
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

    $wpdb = new FakeWpdb();
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

    $wpdb = new FakeWpdb();
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

    $wpdb = new FakeWpdb();
}

function test_event_store_database_unavailable_fails_closed()
{
    global $wpdb;
    $wpdb = null;
    $failed = false;
    try { (new EventStore())->remember('evt_no_database', 3600); } catch (RuntimeException $e) { $failed = true; }
    assertSameValue(true, $failed, 'Unavailable database must never use transient lock fallback.');
    $wpdb = new FakeWpdb();
}

function test_event_store_stale_owner_cannot_release_or_commit_successor()
{
    global $wpdb;
    paymos_reset_test_state();
    $a = new EventStore();
    assertSameValue(true, $a->remember('evt_owner', 3600), 'A acquires.');
    $wpdb->rows[md5('evt_owner')]['expires_at'] = time() - 1;
    $b = new EventStore();
    assertSameValue(true, $b->remember('evt_owner', 3600), 'B takes expired owner using CAS.');
    $failed = false;
    try { $a->commit(); } catch (RuntimeException $e) { $failed = true; }
    assertSameValue(true, $failed, 'Stale owner cannot commit successor.');
    $a->release();
    assertSameValue(false, (new EventStore())->remember('evt_owner', 3600), 'Stale release leaves B reserved.');
    $b->commit();
    assertSameValue(true, $b->isCommitted('evt_owner'), 'Successor commits.');
}

function test_event_store_purges_bounded_expired_rows_including_crashed_workers()
{
    global $wpdb;
    paymos_reset_test_state();
    for ($i = 0; $i < 120; $i++) {
        $wpdb->rows[(string) $i] = array('event_hash' => (string) $i, 'expires_at' => time() - 1, 'status' => 'processing');
    }
    $wpdb->rows['live'] = array('event_hash' => 'live', 'expires_at' => time() + 300, 'status' => 'processing');
    EventStore::purgeExpired();
    assertSameValue(21, count($wpdb->rows), 'At most 100 expired rows purged, live reservation preserved.');
    $wpdb = new FakeWpdb();
}

function test_event_store_expired_takeover_cas_rejects_aba()
{
    global $wpdb;
    paymos_reset_test_state();
    $old = new EventStore();
    $old->remember('evt_cas', 3600);
    $wpdb->rows[md5('evt_cas')]['expires_at'] = time() - 1;
    $b = new EventStore();
    $wpdb->beforeUpdate = static function () use ($b) { assertSameValue(true, $b->remember('evt_cas', 3600), 'B wins while A holds old observed row.'); };
    $a = new EventStore();
    assertSameValue(false, $a->remember('evt_cas', 3600), 'A cannot replace B using stale SELECT.');
    $b->commit();
    assertSameValue(true, $a->isCommitted('evt_cas'), 'B retains committed state.');
}
