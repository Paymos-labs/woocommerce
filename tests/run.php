<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

// Compile every plugin source file, not just the ones the tests touch: the
// platform loads all of them, so a defect in an untested file must fail HERE.
$_srcRoot = PAYMOS_WC_PLUGIN_DIR;
$_iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
    $_srcRoot,
    FilesystemIterator::SKIP_DOTS
));
$_files = array();
foreach ($_iter as $_f) {
    if (!$_f->isFile() || $_f->getExtension() !== 'php') {
        continue;
    }
    $_rel = str_replace(DIRECTORY_SEPARATOR, '/', substr($_f->getPathname(), strlen($_srcRoot)));
    // uninstall.php is executed by WordPress alone, guarded by an exit.
    if (preg_match('!(^|/)(tests?|vendor|node_modules|\.github)(/|$)!', $_rel)
        || basename($_rel) === 'uninstall.php') {
        continue;
    }
    $_files[] = $_f->getPathname();
}
sort($_files);

// Constants the harness itself pre-defined may be re-defined by the plugin's
// entry file (the platform loads that file FIRST in production). Tolerate
// exactly those collisions; anything else — including a real duplicate between
// two plugin files — still fails the run.
$_predefined = get_defined_constants(true);
$_predefined = isset($_predefined['user']) ? $_predefined['user'] : array();
foreach ($_files as $_file) {
    set_error_handler(static function ($severity, $message, $file, $line) use ($_predefined) {
        if (preg_match('/Constant (\\w+) already defined/', $message, $_m)
            && array_key_exists($_m[1], $_predefined)) {
            return true;
        }
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
    try {
        require_once $_file;
    } finally {
        restore_error_handler();
    }
}
unset($_srcRoot, $_iter, $_files, $_file, $_rel, $_f, $_predefined);
require __DIR__ . '/ConfigTest.php';
require __DIR__ . '/ConnectStateStoreTest.php';
require __DIR__ . '/GatewaySettingsTest.php';
require __DIR__ . '/WordPressTransportTest.php';
require __DIR__ . '/EventStoreTest.php';
require __DIR__ . '/OrderMapperTest.php';
require __DIR__ . '/WebhookControllerTest.php';
require __DIR__ . '/ReconcilerTest.php';
require __DIR__ . '/PaidLateCallbackTest.php';
require __DIR__ . '/PublicationLifecycleTest.php';

function home_url($path = '')
{
    return 'https://shop.example.test/' . ltrim($path, '/');
}

function paymos_test_connect_credentials(array $credentials)
{
    PaymosWooCommerce\ConnectStateStore::save(paymos_test_connect_state());
    $requests = 0;
    $GLOBALS['paymos_test_remote_handler'] = static function ($url, array $args) use ($credentials, &$requests) {
        $requests++;
        assertSameValue('https://app.paymos.io/connect/token', $url, 'Connect must poll the token endpoint.');
        assertSameValue('POST', $args['method'], 'Connect must use POST.');
        return array(
            'response' => array('code' => 200),
            'body' => json_encode(array('paymos_config' => array(
                'plugin' => 'woocommerce',
                'source_url' => rtrim(home_url('/'), '/'),
                'environments' => $credentials,
            ))),
            'headers' => array(),
        );
    };
    PaymosWooCommerce\ConnectController::poll();
    $GLOBALS['paymos_test_remote_handler'] = null;
    assertSameValue(1, $requests, 'Connect must consume one stubbed response.');
    assertSameValue(array(), PaymosWooCommerce\ConnectStateStore::load(), 'Connect must clear its device state.');
}

function test_connect_preserves_refund_fields_and_existing_credentials()
{
    paymos_reset_test_state();
    $credentials = paymos_test_credentials();
    $credentials['sandbox'] += array('capabilities' => array('invoice_refunds_v1', 'future_capability'),
        'refund_contract_version' => 1, 'refunds_enabled' => true);
    $credentials['live'] += array('capabilities' => array(), 'refund_contract_version' => 2, 'refunds_enabled' => false);
    assertSameValue(array(), PaymosWooCommerce\Config::environment_config('sandbox'), 'Prime the empty credential cache.');

    paymos_test_connect_credentials($credentials);
    $stored = Paymos\Plugin\WordPressEncryptedOption::load(
        PaymosWooCommerce\CredentialStore::OPTION_KEY, 'paymos-for-woocommerce-credentials-v1'
    );
    foreach ($credentials as $environment => $values) {
        assertSameValue($values, $stored['environments'][$environment], 'Connect must persist all fields in the encrypted envelope.');
        assertSameValue($values, PaymosWooCommerce\Config::environment_config($environment), 'Connect must refresh cached credentials.');
    }
    PaymosWooCommerce\Config::reset_cache();
    assertSameValue($credentials, PaymosWooCommerce\CredentialStore::load(), 'All credential fields must survive a fresh load.');
}

function test_connect_legacy_response_clears_previous_refund_capability()
{
    paymos_reset_test_state();
    $credentials = paymos_test_credentials();
    $enabled = $credentials;
    $enabled['sandbox'] += array('capabilities' => array('invoice_refunds_v1'), 'refund_contract_version' => 1, 'refunds_enabled' => true);
    paymos_store_credentials($enabled);
    assertSameValue(true, Paymos\Plugin\CredentialSet::refundsEnabled(PaymosWooCommerce\Config::environment_config('sandbox')), 'Prime the enabled credential cache.');

    paymos_test_connect_credentials($credentials);
    foreach ($credentials as $environment => $values) {
        $expected = $values + array('capabilities' => array(), 'refund_contract_version' => null, 'refunds_enabled' => false);
        assertSameValue($expected, PaymosWooCommerce\Config::environment_config($environment), 'Legacy Connect must preserve secrets and clear refund permissions.');
    }
}

// RefundAdapterTest.php supplies the refund fixtures and runs these service cases.
function paymos_test_connected_native_refund(array $refundFields, &$api)
{
    refund_setup($api);
    $credentials = paymos_test_credentials();
    $credentials['sandbox']['project_id'] = 'prj_123';
    $credentials['sandbox'] += $refundFields;
    paymos_test_connect_credentials($credentials);
    $GLOBALS['paymos_test_remote_handler'] = static function ($url, array $args) use ($api) {
        $response = $api->request($args['method'], $url, $args['headers'], $args['body'], $args['timeout']);
        return array('response' => array('code' => $response->statusCode()),
            'body' => $response->body(), 'headers' => $response->headers());
    };
    $_POST = refund_full_input() + array('order_id' => 100, 'paymos_refund_token' => 'token_connect_native_01');
    PaymosWooCommerce\RefundAdapter::nativeAjax();
}

function refund_test_connected_native_entry_without_capability_refuses_before_http()
{
    foreach (array(array(), array('capabilities' => array(), 'refund_contract_version' => 1, 'refunds_enabled' => true)) as $fields) {
        paymos_test_connected_native_refund($fields, $api);
        assertSameValue(array(), $api->requests, 'Missing capability must reject before any refund HTTP, including GET.');
        assertSameValue(array(), $GLOBALS['wpdb']->intents, 'Missing capability must not create an intent.');
        assertSameValue(0, $GLOBALS['refund_native_writes'], 'Missing capability must not book a native refund.');
        assertSameValue(200, $GLOBALS['paymos_test_json_status'], 'Native refusal must retain the existing status.');
        assertSameValue(array('success' => false, 'data' => array('error' =>
            'The refund was not started. With Debug logging on, the reason is written to the WooCommerce logs.')),
            $GLOBALS['paymos_test_json_response'], 'Native refusal must retain the existing error text.');
    }
}

function refund_test_connected_native_entry_requires_version_one_contract()
{
    foreach (array(
        array('refund_contract_version' => 2, 'refunds_enabled' => true),
        array('refund_contract_version' => '1', 'refunds_enabled' => true),
    ) as $fields) {
        $fields['capabilities'] = array('invoice_refunds_v1');
        paymos_test_connected_native_refund($fields, $api);
        assertSameValue(array(), $api->requests, 'Unsupported contract must reject before HTTP.');
        assertSameValue(array(), $GLOBALS['wpdb']->intents, 'Rejected credentials must not create an intent.');
        assertSameValue(false, $GLOBALS['paymos_test_json_response']['success'], 'Native entry must return an error.');
    }
}

function refund_test_connected_native_entry_does_not_freeze_the_server_enablement_snapshot()
{
    paymos_test_connected_native_refund(array('capabilities' => array('invoice_refunds_v1'),
        'refund_contract_version' => 1, 'refunds_enabled' => false), $api);
    assertSameValue(1, count($api->posts()), 'Connected while disabled can submit after the current server enables refunds.');
    assertSameValue(0, $GLOBALS['refund_native_writes'], 'Pending cash does not create a native financial refund.');
}

function refund_test_connected_native_entry_with_capability_submits_existing_full_refund()
{
    paymos_test_connected_native_refund(array('capabilities' => array('invoice_refunds_v1'),
        'refund_contract_version' => 1, 'refunds_enabled' => true), $api);
    assertSameValue(1, count($api->posts()), 'Connected credentials must allow exactly one refund POST.');
    assertSameValue(1, count($GLOBALS['wpdb']->intents), 'Native entry must persist one operation.');
    $payload = json_decode($api->posts()[0]['body'], true);
    assertSameValue('inv_123', $payload['invoice_id'], 'Native entry must use the bound invoice.');
    assertSameValue('full', $payload['mode'], 'Native entry must keep full-refund behavior.');
    assertSameValue(0, $GLOBALS['refund_native_writes'], 'Pending cash must not book a native refund.');
    $operationId = $GLOBALS['paymos_test_json_response']['paymos_refund']['operation_id'];
    $intent = (new PaymosWooCommerce\RefundIntentStore())->getIntent($operationId);
    assertSameValue('wd_1', $intent->withdrawalId(), 'Native entry must report the accepted cash operation.');
}

require_once __DIR__ . '/RefundAdapterTest.php';
require __DIR__ . '/GatewayRenewalTest.php';

$GLOBALS['wpdb'] = new FakeWpdb();
$count = 0;

foreach (get_defined_functions()['user'] as $function) {
    if (strpos($function, 'test_') !== 0) {
        continue;
    }

    $function();
    $count++;
    echo "PASS {$function}\n";
}

paymos_reset_test_state();

echo "OK {$count} tests\n";
