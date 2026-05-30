<?php

declare(strict_types=1);

use PaymosWooCommerce\Config;

function test_config_v2_returns_sandbox_when_mode_is_sandbox()
{
    paymos_reset_test_state();
    paymos_set_option(Config::OPTION_KEY, array('mode' => 'sandbox'));
    paymos_write_generated_config("array(
        'config_version' => 2,
        'environments' => array(
            'sandbox' => array(
                'api_key' => 'pk_test_1234567890',
                'api_secret' => 'sk_test_secret',
                'project_id' => 'prj_sandbox',
                'webhook_secret' => 'whsec_test_secret',
            ),
            'live' => array(
                'api_key' => 'pk_live_1234567890',
                'api_secret' => 'sk_live_secret',
                'project_id' => 'prj_live',
                'webhook_secret' => 'whsec_live_secret',
            ),
        ),
    )");

    $config = Config::environment_config();

    assertSameValue('sandbox', Config::mode(), 'mode must read sandbox from Woo settings.');
    assertSameValue('pk_test_1234567890', $config['api_key'], 'sandbox mode must use sandbox API key.');
    assertSameValue('prj_sandbox', $config['project_id'], 'sandbox mode must use sandbox project id.');
    assertSameValue('https://api.paymos.io', $config['base_url'], 'sandbox config must default base URL.');
}

function test_config_v2_returns_live_when_mode_is_live()
{
    paymos_reset_test_state();
    paymos_set_option(Config::OPTION_KEY, array('mode' => 'live'));
    paymos_write_generated_config("array(
        'config_version' => 2,
        'environments' => array(
            'sandbox' => array('api_key' => 'pk_test_key', 'api_secret' => 'sk_test_secret', 'project_id' => 'prj_sandbox', 'webhook_secret' => 'whsec_test'),
            'live' => array('api_key' => 'pk_live_key', 'api_secret' => 'sk_live_secret', 'project_id' => 'prj_live', 'webhook_secret' => 'whsec_live'),
        ),
    )");

    $config = Config::environment_config();

    assertSameValue('live', Config::mode(), 'mode must read live from Woo settings.');
    assertSameValue('pk_live_key', $config['api_key'], 'live mode must use live API key.');
    assertSameValue('prj_live', $config['project_id'], 'live mode must use live project id.');
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
    paymos_write_generated_config("array(
        'config_version' => 2,
        'environments' => array(
            'sandbox' => array('webhook_secret' => 'whsec_test'),
            'live' => array('webhook_secret' => 'whsec_live'),
        ),
    )");

    $secrets = Config::webhook_secrets();

    assertSameValue('whsec_test', $secrets['sandbox'], 'webhook secrets must include sandbox secret.');
    assertSameValue('whsec_live', $secrets['live'], 'webhook secrets must include live secret.');
}

function test_config_legacy_flat_config_remains_readable()
{
    paymos_reset_test_state();
    paymos_set_option(Config::OPTION_KEY, array('mode' => 'live'));
    paymos_write_generated_config("array(
        'environment' => 'live',
        'base_url' => 'https://api.paymos.io',
        'api_key' => 'pk_live_legacy',
        'api_secret' => 'sk_live_legacy',
        'project_id' => 'prj_legacy',
        'webhook_secret' => 'whsec_legacy',
    )");

    $config = Config::environment_config('live');

    assertSameValue('pk_live_legacy', $config['api_key'], 'legacy flat config must expose API key.');
    assertSameValue('whsec_legacy', Config::webhook_secrets()['live'], 'legacy flat config must expose webhook secret.');
}

function test_config_generated_values_override_woo_option_secrets()
{
    paymos_reset_test_state();
    paymos_set_option(Config::OPTION_KEY, array(
        'mode' => 'live',
        'api_key' => 'pk_live_from_settings',
        'api_secret' => 'sk_live_from_settings',
        'project_id' => 'prj_settings',
        'webhook_secret' => 'whsec_settings',
    ));
    paymos_write_generated_config("array(
        'config_version' => 2,
        'environments' => array(
            'live' => array(
                'api_key' => 'pk_live_generated',
                'api_secret' => 'sk_live_generated',
                'project_id' => 'prj_generated',
                'webhook_secret' => 'whsec_generated',
            ),
        ),
    )");

    $config = Config::environment_config();

    assertSameValue('pk_live_generated', $config['api_key'], 'generated API key must override Woo option.');
    assertSameValue('sk_live_generated', $config['api_secret'], 'generated API secret must override Woo option.');
    assertSameValue('prj_generated', $config['project_id'], 'generated project id must override Woo option.');
    assertSameValue('whsec_generated', $config['webhook_secret'], 'generated webhook secret must override Woo option.');
}

