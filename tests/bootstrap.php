<?php

declare(strict_types=1);

define('ABSPATH', __DIR__);
define('PAYMOS_WC_PLUGIN_DIR', dirname(__DIR__) . DIRECTORY_SEPARATOR);

$GLOBALS['paymos_test_options'] = array();
$GLOBALS['paymos_test_transients'] = array();

spl_autoload_register(static function ($class) {
    $prefix = 'PaymosWooCommerce\\';
    if (strncmp($class, $prefix, strlen($prefix)) === 0) {
        $relative = substr($class, strlen($prefix));
        require PAYMOS_WC_PLUGIN_DIR . 'includes/' . str_replace('\\', '/', $relative) . '.php';
        return;
    }

    $sdkPrefix = 'Paymos\\';
    if (strncmp($class, $sdkPrefix, strlen($sdkPrefix)) === 0) {
        $relative = substr($class, strlen($sdkPrefix));
        $candidates = array(
            PAYMOS_WC_PLUGIN_DIR . 'vendor/paymos/php-sdk/src/' . str_replace('\\', '/', $relative) . '.php',
            getenv('PAYMOS_SDK_SRC')
                ? rtrim(getenv('PAYMOS_SDK_SRC'), '/\\') . '/' . str_replace('\\', '/', $relative) . '.php'
                : null,
            dirname(rtrim(PAYMOS_WC_PLUGIN_DIR, '/\\')) . '/php-sdk/src/' . str_replace('\\', '/', $relative) . '.php',
        );
        foreach ($candidates as $candidate) {
            if ($candidate !== null && is_file($candidate)) {
                require $candidate;
                return;
            }
        }
    }
});

function __($text, $domain = null)
{
    return $text;
}

function wc_format_decimal($number, $dp = false, $trim_zeros = false)
{
    $decimals = $dp === false ? 2 : (int) $dp;
    return number_format((float) $number, $decimals, '.', '');
}

function get_option($key, $default = false)
{
    return array_key_exists($key, $GLOBALS['paymos_test_options'])
        ? $GLOBALS['paymos_test_options'][$key]
        : $default;
}

function get_transient($key)
{
    return array_key_exists((string) $key, $GLOBALS['paymos_test_transients'])
        ? $GLOBALS['paymos_test_transients'][(string) $key]
        : false;
}

function set_transient($key, $value, $expiration = 0)
{
    $GLOBALS['paymos_test_transients'][(string) $key] = $value;
    return true;
}

function delete_transient($key)
{
    unset($GLOBALS['paymos_test_transients'][(string) $key]);
    return true;
}

function add_option($key, $value = '', $deprecated = '', $autoload = 'yes')
{
    if (array_key_exists((string) $key, $GLOBALS['paymos_test_transients'])) {
        return false;
    }

    $GLOBALS['paymos_test_transients'][(string) $key] = $value;
    return true;
}

function delete_option($key)
{
    unset($GLOBALS['paymos_test_transients'][(string) $key]);
    return true;
}

function rest_url($path = '')
{
    return 'https://shop.example.com/wp-json/' . ltrim((string) $path, '/');
}

function paymos_set_option($key, $value)
{
    $GLOBALS['paymos_test_options'][$key] = $value;
}

function paymos_reset_test_state()
{
    $GLOBALS['paymos_test_options'] = array();
    $GLOBALS['paymos_test_transients'] = array();
    $config = PAYMOS_WC_PLUGIN_DIR . 'paymos-config.php';
    if (is_file($config)) {
        unlink($config);
    }

    if (class_exists('PaymosWooCommerce\\Config')) {
        PaymosWooCommerce\Config::reset_for_tests();
    }

    if (class_exists('PaymosWooCommerce\\WebhookController') && method_exists('PaymosWooCommerce\\WebhookController', 'set_client_factory_for_tests')) {
        PaymosWooCommerce\WebhookController::set_client_factory_for_tests(null);
    }

    unset($GLOBALS['paymos_test_wc_orders']);
}

function paymos_write_generated_config($php)
{
    file_put_contents(PAYMOS_WC_PLUGIN_DIR . 'paymos-config.php', "<?php\n\nreturn " . $php . ";\n");

    if (class_exists('PaymosWooCommerce\\Config')) {
        PaymosWooCommerce\Config::reset_for_tests();
    }
}

function assertSameValue($expected, $actual, $message)
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . ' Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function assertTrueValue($actual, $message)
{
    if ($actual !== true) {
        throw new RuntimeException($message);
    }
}

function paymos_signed_header($secret, $body, $timestamp = 1709000000)
{
    return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
}
