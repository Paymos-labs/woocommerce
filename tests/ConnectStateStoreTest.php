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
