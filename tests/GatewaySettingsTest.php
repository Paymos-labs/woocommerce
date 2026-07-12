<?php

declare(strict_types=1);

use PaymosWooCommerce\Config;
use PaymosWooCommerce\Gateway;

function test_gateway_admin_exposes_no_manual_credential_fields()
{
    paymos_reset_test_state();
    $gateway = new Gateway();
    assertSameValue(false, array_key_exists('credentials', $gateway->form_fields), 'credentials must come only from Connect.');
    $serialized = serialize($gateway->form_fields);
    assertTrueValue(strpos($serialized, 'api_secret') === false, 'settings schema must not expose an API secret input.');
    assertTrueValue(strpos($serialized, 'webhook_secret') === false, 'settings schema must not expose a webhook secret input.');
}

function test_gateway_status_html_masks_saved_identifiers()
{
    paymos_reset_test_state();
    paymos_set_option(Config::OPTION_KEY, array('mode' => 'sandbox'));
    paymos_store_credentials(paymos_test_credentials());
    $gateway = new Gateway();

    $html = $gateway->generate_paymos_config_status_html('config_status', array(
        'title' => 'Connection status',
        'description' => 'Active configuration',
    ));

    assertTrueValue(strpos($html, 'pk_test_1234567890') === false, 'status HTML must not render the full API key.');
    assertTrueValue(strpos($html, 'prj_sandbox') === false, 'status HTML must not render the full project ID.');
    assertTrueValue(strpos($html, 'pk_test_...7890') !== false, 'status HTML must render only the masked API key.');
    assertTrueValue(strpos($html, '***********') !== false, 'status HTML must render only the masked project ID.');
}

function test_gateway_is_unavailable_until_active_environment_is_configured()
{
    paymos_reset_test_state();
    paymos_set_option(Config::OPTION_KEY, array('enabled' => 'yes', 'mode' => 'sandbox'));
    $gateway = new Gateway();
    assertSameValue(false, $gateway->is_available(), 'gateway must be unavailable without sandbox credentials.');

    paymos_store_credentials(array('sandbox' => paymos_test_credentials()['sandbox']));
    assertSameValue(true, $gateway->is_available(), 'gateway must become available when active environment is configured.');
}
