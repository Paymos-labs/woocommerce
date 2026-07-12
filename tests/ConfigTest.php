<?php

declare(strict_types=1);

use PaymosWooCommerce\Config;
use PaymosWooCommerce\CredentialStore;

function paymos_test_credentials()
{
    return array(
        'sandbox' => array(
            'base_url' => 'https://api.paymos.io',
            'api_key' => 'pk_test_1234567890',
            'api_secret' => 'sk_test_secret',
            'project_id' => 'prj_sandbox',
            'webhook_secret' => 'whsec_test_secret',
        ),
        'live' => array(
            'base_url' => 'https://api.paymos.io',
            'api_key' => 'pk_live_1234567890',
            'api_secret' => 'sk_live_secret',
            'project_id' => 'prj_live',
            'webhook_secret' => 'whsec_live_secret',
        ),
    );
}

function test_config_returns_encrypted_sandbox_credentials()
{
    paymos_reset_test_state();
    paymos_set_option(Config::OPTION_KEY, array('mode' => 'sandbox'));
    paymos_store_credentials(paymos_test_credentials());

    $config = Config::environment_config();

    assertSameValue('sandbox', Config::mode(), 'mode must read sandbox from Woo settings.');
    assertSameValue('pk_test_1234567890', $config['api_key'], 'sandbox API key must load from credential store.');
    assertSameValue('prj_sandbox', $config['project_id'], 'sandbox project id must load from credential store.');
    assertSameValue('https://api.paymos.io', $config['base_url'], 'sandbox config must use trusted API URL.');
}

function test_config_returns_live_credentials()
{
    paymos_reset_test_state();
    paymos_set_option(Config::OPTION_KEY, array('mode' => 'live'));
    paymos_store_credentials(paymos_test_credentials());

    $config = Config::environment_config();

    assertSameValue('live', Config::mode(), 'mode must read live from Woo settings.');
    assertSameValue('pk_live_1234567890', $config['api_key'], 'live API key must load.');
    assertSameValue('prj_live', $config['project_id'], 'live project id must load.');
}

function test_config_invalid_mode_falls_back_to_sandbox()
{
    paymos_reset_test_state();
    paymos_set_option(Config::OPTION_KEY, array('mode' => 'broken'));

    assertSameValue('sandbox', Config::mode(), 'invalid mode must fall back to sandbox.');
}

function test_config_webhook_secrets_returns_both_environments()
{
    paymos_reset_test_state();
    paymos_store_credentials(paymos_test_credentials());

    $secrets = Config::webhook_secrets();

    assertSameValue('whsec_test_secret', $secrets['sandbox'], 'webhook secrets must include sandbox secret.');
    assertSameValue('whsec_live_secret', $secrets['live'], 'webhook secrets must include live secret.');
}

function test_credential_store_never_persists_plaintext()
{
    paymos_reset_test_state();
    paymos_store_credentials(paymos_test_credentials());

    $stored = (string) get_option(CredentialStore::OPTION_KEY, '');

    assertTrueValue($stored !== '', 'encrypted credential envelope must be stored.');
    assertTrueValue(strpos($stored, 'sk_live_secret') === false, 'API secret must not be stored in plaintext.');
    assertTrueValue(strpos($stored, 'whsec_test_secret') === false, 'webhook secret must not be stored in plaintext.');
    assertTrueValue(strpos($stored, 'A256GCM') !== false, 'credential envelope must identify AES-256-GCM.');
}

function test_credential_store_rejects_environment_mismatch()
{
    paymos_reset_test_state();
    $credentials = paymos_test_credentials();
    $credentials['sandbox']['api_secret'] = 'sk_live_wrong';
    $threw = false;

    try {
        CredentialStore::save($credentials);
    } catch (InvalidArgumentException $exception) {
        $threw = true;
    }

    assertTrueValue($threw, 'sandbox config must reject a live API secret.');
    assertSameValue('', get_option(CredentialStore::OPTION_KEY, ''), 'invalid credentials must not be persisted.');
}

function test_credential_store_rejects_untrusted_api_url_components()
{
    paymos_reset_test_state();
    $credentials = paymos_test_credentials();
    $credentials['live']['base_url'] = 'https://api.paymos.io/redirect?target=attacker';
    $threw = false;

    try {
        CredentialStore::save($credentials);
    } catch (InvalidArgumentException $exception) {
        $threw = true;
    }

    assertTrueValue($threw, 'API URL must reject paths and query strings outside the trusted endpoint.');
    assertSameValue('', get_option(CredentialStore::OPTION_KEY, ''), 'untrusted API URL must not be persisted.');
}

function test_credential_store_reports_database_write_failure()
{
    paymos_reset_test_state();
    $GLOBALS['paymos_test_failed_option_updates'][] = CredentialStore::OPTION_KEY;
    $threw = false;

    try {
        CredentialStore::save(paymos_test_credentials());
    } catch (RuntimeException $exception) {
        $threw = true;
    }

    assertTrueValue($threw, 'credential store must fail closed when WordPress cannot save the encrypted option.');
    assertSameValue('', get_option(CredentialStore::OPTION_KEY, ''), 'failed database write must not appear successful.');
}

function test_tampered_credential_envelope_fails_closed()
{
    paymos_reset_test_state();
    paymos_store_credentials(paymos_test_credentials());
    $envelope = json_decode((string) get_option(CredentialStore::OPTION_KEY, ''), true);
    $envelope['ciphertext'] = base64_encode('tampered');
    update_option(CredentialStore::OPTION_KEY, json_encode($envelope), false);
    Config::reset_cache();

    assertSameValue(array(), Config::environment_config('sandbox'), 'tampered ciphertext must not expose credentials.');
    assertTrueValue(Config::credential_error() !== '', 'tampered ciphertext must produce an admin-safe storage error.');
}
