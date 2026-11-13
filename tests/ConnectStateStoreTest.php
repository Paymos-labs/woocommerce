<?php

declare(strict_types=1);

use PaymosWooCommerce\ConnectStateStore;

function paymos_test_connect_state()
{
    return array(
        'device_code' => 'device-secret-code',
        'user_code' => 'ABCD-EFGH',
        'verification_url' => 'https://app.paymos.io/connect/cms/verify?user_code=ABCD-EFGH',
        'interval' => 5,
        'expires_in' => 600,
        'started_at' => time(),
    );
}

function test_connect_state_is_encrypted_and_round_trips()
{
    paymos_reset_test_state();
    ConnectStateStore::save(paymos_test_connect_state());

    $stored = (string) get_option(ConnectStateStore::OPTION_KEY, '');
    $loaded = ConnectStateStore::load();

    assertTrueValue($stored !== '', 'connect state must be persisted.');
    assertTrueValue(strpos($stored, 'device-secret-code') === false, 'device code must not be stored in plaintext.');
    assertSameValue('device-secret-code', $loaded['device_code'], 'valid encrypted connect state must round-trip.');
}

function test_expired_connect_state_is_deleted()
{
    paymos_reset_test_state();
    $state = paymos_test_connect_state();
    $state['started_at'] = time() - 601;
    ConnectStateStore::save($state);

    assertSameValue(array(), ConnectStateStore::load(), 'expired connect state must not be returned.');
    assertSameValue('', get_option(ConnectStateStore::OPTION_KEY, ''), 'expired connect state must be deleted.');
}

function test_tampered_connect_state_fails_closed()
{
    paymos_reset_test_state();
    ConnectStateStore::save(paymos_test_connect_state());
    $envelope = json_decode((string) get_option(ConnectStateStore::OPTION_KEY, ''), true);
    $envelope['ciphertext'] = base64_encode('tampered');
    update_option(ConnectStateStore::OPTION_KEY, json_encode($envelope), false);
    $threw = false;

    try {
        ConnectStateStore::load();
    } catch (RuntimeException $exception) {
        $threw = true;
    }

    assertTrueValue($threw, 'tampered connect state must not be accepted.');
}

function test_r31_connect_stale_poll_cannot_clear_or_install_credentials()
{
    foreach (array('connected', 'denied', 'transport') as $result) {
        paymos_reset_test_state();
        $original = paymos_test_credentials();
        paymos_store_credentials($original);
        $original = PaymosWooCommerce\CredentialStore::load();
        $flowA = ConnectStateStore::save(paymos_test_connect_state());
        $_POST['flow_id'] = $flowA;
        $flowB = '';
        $GLOBALS['paymos_test_remote_handler'] = static function ($url, $args) use ($result, &$flowB) {
            $stateB = paymos_test_connect_state();
            $stateB['device_code'] = 'device-B';
            $flowB = ConnectStateStore::save($stateB);
            if ($result === 'transport') { throw new RuntimeException('connection lost'); }
            if ($result === 'denied') {
                return array('response' => array('code' => 400), 'body' => '{"error":"access_denied"}', 'headers' => array());
            }
            $credentials = paymos_test_credentials();
            $credentials['sandbox']['project_id'] = 'stale-project';
            return array('response' => array('code' => 200), 'headers' => array(),
                'body' => json_encode(array('paymos_config' => array('plugin' => 'woocommerce',
                    'source_url' => rtrim(home_url('/'), '/'), 'environments' => $credentials))));
        };
        PaymosWooCommerce\ConnectController::poll();
        assertSameValue($flowB, ConnectStateStore::load()['flow_id'], 'A stale poll must preserve flow B.');
        assertSameValue('device-B', ConnectStateStore::load()['device_code'], 'B keeps its device code.');
        assertSameValue($original, PaymosWooCommerce\CredentialStore::load(), 'Stale success must not install A credentials.');
        assertSameValue(false, $GLOBALS['paymos_test_json_response']['success'], 'Stale poll must report failure.');
    }
}

function test_r31_connect_rejects_missing_or_stale_flow_before_http()
{
    paymos_reset_test_state();
    $flow = ConnectStateStore::save(paymos_test_connect_state());
    foreach (array('', 'another-flow') as $requested) {
        $_POST['flow_id'] = $requested;
        PaymosWooCommerce\ConnectController::poll();
        assertSameValue(array(), $GLOBALS['paymos_test_http_requests'], 'Wrong flow must not poll another tab device.');
        assertSameValue($flow, ConnectStateStore::load()['flow_id'], 'Wrong flow cannot clear current state.');
    }
}

function test_r31_connect_mutations_share_the_generation_mutex()
{
    paymos_reset_test_state();
    $flow = ConnectStateStore::save(paymos_test_connect_state());
    $lock = new Paymos\Plugin\WordPressOrderLock('woocommerce-connect', ConnectStateStore::OPTION_KEY);
    assertSameValue(true, $lock->acquire(), 'Test owns the connection mutex.');
    try {
        foreach (array(
            static function () { ConnectStateStore::save(paymos_test_connect_state()); },
            static function () use ($flow) { ConnectStateStore::clear($flow); },
            static function () use ($flow) { ConnectStateStore::complete($flow, paymos_test_credentials()); },
        ) as $mutate) {
            $blocked = false;
            try { $mutate(); } catch (RuntimeException $expected) { $blocked = true; }
            assertSameValue(true, $blocked, 'All state/credential mutations must take the same mutex.');
        }
    } finally { $lock->release(); }
    assertSameValue($flow, ConnectStateStore::load()['flow_id'], 'Blocked mutation leaves original flow.');
}

function test_connect_poll_rejects_noncanonical_flow_without_side_effects()
{
    foreach (array('missing', 'array', 'spaces', 'html', 'uppercase', 'foreign', 'slash') as $case) {
        paymos_reset_test_state();
        PaymosWooCommerce\CredentialStore::save(paymos_test_credentials());
        $original = PaymosWooCommerce\CredentialStore::load();
        $flow = ConnectStateStore::save(paymos_test_connect_state());
        $inputs = array(
            'array' => array($flow),
            'spaces' => ' ' . $flow . ' ',
            'html' => '<b>' . $flow . '</b>',
            'uppercase' => 'A' . substr($flow, 1),
            'foreign' => ($flow[0] === 'a' ? 'b' : 'a') . substr($flow, 1),
            'slash' => chr(92) . $flow,
        );
        $_POST = $case === 'missing' ? array() : array('flow_id' => $inputs[$case]);
        PaymosWooCommerce\ConnectController::poll();
        assertSameValue(array(), $GLOBALS['paymos_test_http_requests'], $case . ': invalid flow must not reach the provider.');
        assertSameValue($flow, ConnectStateStore::load()['flow_id'], $case . ': invalid flow cannot clear the active request.');
        assertSameValue($original, PaymosWooCommerce\CredentialStore::load(), $case . ': credentials must remain unchanged.');
    }
}

function test_connect_ajax_requires_nonce_and_capability_before_side_effects()
{
    foreach (array('start', 'poll') as $handler) {
        foreach (array('nonce', 'capability') as $refusal) {
            paymos_reset_test_state();
            PaymosWooCommerce\CredentialStore::save(paymos_test_credentials());
            $original = PaymosWooCommerce\CredentialStore::load();
            $flow = ConnectStateStore::save(paymos_test_connect_state());
            $_POST = array('flow_id' => $flow);
            $GLOBALS['paymos_test_terminate_json_errors'] = true;
            if ($refusal === 'nonce') {
                $GLOBALS['paymos_test_ajax_nonce_valid'] = false;
            } else {
                $GLOBALS['paymos_test_capabilities']['manage_woocommerce'] = false;
            }
            $status = null;
            try {
                PaymosWooCommerce\ConnectController::$handler();
            } catch (PaymosTestAjaxExit $expected) {
                $status = $expected->getCode();
            }
            assertSameValue(403, $status, $handler . ': authorization refusal must end the request.');
            assertSameValue(array(array('paymos_woocommerce_connect', 'nonce', true)),
                $GLOBALS['paymos_test_ajax_nonce_checks'], $handler . ': exactly one correct nonce check is required.');
            assertSameValue(array(), $GLOBALS['paymos_test_http_requests'], $handler . ': refusal must not reach the provider.');
            assertSameValue($flow, ConnectStateStore::load()['flow_id'], $handler . ': refusal cannot replace or clear a flow.');
            assertSameValue($original, PaymosWooCommerce\CredentialStore::load(), $handler . ': refusal cannot replace credentials.');
        }
    }
    paymos_reset_test_state();
}
