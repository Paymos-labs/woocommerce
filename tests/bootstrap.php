<?php

declare(strict_types=1);

define('ABSPATH', __DIR__);

// WooCommerce Blocks payment integration base (loaded only when Woo Blocks is
// active at runtime; stubbed so the compile-all gate can include Blocks.php).
if (!class_exists('Automattic\\WooCommerce\\Blocks\\Payments\\Integrations\\AbstractPaymentMethodType')) {
    abstract class AbstractPaymentMethodTypeStubForGate
    {
        protected $name = '';

        public function initialize() {}
        public function is_active() { return false; }
        public function get_payment_method_script_handles() { return array(); }
        public function get_payment_method_data() { return array(); }
    }
}
if (!class_exists('Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType')) {
    class_alias('AbstractPaymentMethodTypeStubForGate', 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType');
}
define('PAYMOS_WC_PLUGIN_DIR', dirname(__DIR__) . DIRECTORY_SEPARATOR);

// Any deprecation, notice or warning inside plugin code must fail the run:
// platform installers (Magento DI compile above all) escalate PHP 8.4+
// deprecations to fatals, and a silent one here is how rejections slip through.
error_reporting(E_ALL);
set_error_handler(static function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});
define('PAYMOS_WC_PLUGIN_FILE', PAYMOS_WC_PLUGIN_DIR . 'paymos-woocommerce.php');

$GLOBALS['paymos_test_options'] = array();
$GLOBALS['paymos_test_transients'] = array();
$GLOBALS['paymos_test_admin_errors'] = array();
$GLOBALS['paymos_test_failed_option_updates'] = array();
$GLOBALS['paymos_test_remote_handler'] = null;

class WC_Payment_Gateway
{
    public $id = '';
    public $method_title = '';
    public $method_description = '';
    public $icon = '';
    public $has_fields = false;
    public $supports = array();
    public $form_fields = array();
    public $settings = array();
    public $title = '';
    public $description = '';
    public $enabled = 'no';

    public function init_settings()
    {
        $this->settings = get_option('woocommerce_' . $this->id . '_settings', array());
    }

    public function get_option($key, $default = '')
    {
        return isset($this->settings[$key]) ? $this->settings[$key] : $default;
    }

    public function get_field_key($key)
    {
        return 'woocommerce_' . $this->id . '_' . $key;
    }

    public function process_admin_options()
    {
        return true;
    }

    public function is_available()
    {
        return $this->enabled === 'yes';
    }
}

class WC_Admin_Settings
{
    public static function add_error($message)
    {
        $GLOBALS['paymos_test_admin_errors'][] = (string) $message;
    }
}

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

function esc_html__($text, $domain = null)
{
    return (string) $text;
}

function esc_html($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

function esc_attr($text)
{
    return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
}

function apply_filters($hook, $value)
{
    return $value;
}

function plugins_url($path = '', $plugin = '')
{
    return 'https://shop.example.com/wp-content/plugins/paymos-woocommerce/' . ltrim((string) $path, '/');
}

// WP runtime functions the plugin's entry file calls at load time (the
// compile-all gate requires that file, exactly like WordPress does).
if (!function_exists('plugin_dir_path')) {
    function plugin_dir_path($file)
    {
        return rtrim(dirname($file), '/\\') . DIRECTORY_SEPARATOR;
    }
}
if (!function_exists('plugin_dir_url')) {
    function plugin_dir_url($file)
    {
        return 'https://example.test/wp-content/plugins/' . basename(dirname($file)) . '/';
    }
}
if (!function_exists('plugin_basename')) {
    function plugin_basename($file)
    {
        return basename(dirname($file)) . '/' . basename($file);
    }
}
if (!function_exists('load_plugin_textdomain')) {
    function load_plugin_textdomain($domain, $deprecated = false, $relative = false)
    {
        return true;
    }
}
if (!function_exists('register_activation_hook')) {
    function register_activation_hook($file, $callback)
    {
    }
}

if (!function_exists('add_filter')) {
    function add_filter($hook, $callback, $priority = 10, $acceptedArgs = 1)
    {
        return true;
    }
}
if (!function_exists('wc_get_template')) {
    function wc_get_template($template, $args = array(), $templatePath = '', $defaultPath = '')
    {
    }
}
if (!function_exists('wp_kses_post')) {
    function wp_kses_post($content)
    {
        return $content;
    }
}
if (!function_exists('register_deactivation_hook')) {
    function register_deactivation_hook()
    {
        return array();
    }
}
function add_action($hook, $callback, $priority = 10, $acceptedArgs = 1)
{
    return true;
}

function current_user_can($capability)
{
    return true;
}

function wp_unslash($value)
{
    return $value;
}

function sanitize_text_field($value)
{
    return trim(strip_tags((string) $value));
}

function check_admin_referer($action)
{
    return true;
}

function wp_parse_url($url)
{
    return parse_url((string) $url);
}

function wp_delete_file($path)
{
    if (is_file($path)) {
        unlink($path);
    }
}

function wp_safe_remote_request($url, array $args)
{
    if (is_callable($GLOBALS['paymos_test_remote_handler'])) {
        return call_user_func($GLOBALS['paymos_test_remote_handler'], $url, $args);
    }

    return new WP_Error('no_handler', 'No HTTP handler configured.');
}

function is_wp_error($value)
{
    return $value instanceof WP_Error;
}

function wp_remote_retrieve_response_code($response)
{
    return isset($response['response']['code']) ? (int) $response['response']['code'] : 0;
}

function wp_remote_retrieve_body($response)
{
    return isset($response['body']) ? (string) $response['body'] : '';
}

function wp_remote_retrieve_headers($response)
{
    return isset($response['headers']) ? $response['headers'] : array();
}

class WP_Error
{
    public function __construct($code = '', $message = '')
    {
    }
}

function update_option($key, $value, $autoload = null)
{
    if (in_array((string) $key, $GLOBALS['paymos_test_failed_option_updates'], true)) {
        return false;
    }

    $GLOBALS['paymos_test_options'][(string) $key] = $value;
    return true;
}

function wp_salt($scheme = 'auth')
{
    return 'paymos-test-wordpress-salt-' . (string) $scheme;
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
    unset($GLOBALS['paymos_test_options'][(string) $key]);
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
    $GLOBALS['paymos_test_admin_errors'] = array();
    $GLOBALS['paymos_test_failed_option_updates'] = array();
    $GLOBALS['paymos_test_remote_handler'] = null;
    $_POST = array();
    if (class_exists('PaymosWooCommerce\\Config')) {
        PaymosWooCommerce\Config::reset_cache();
    }

    if (class_exists('PaymosWooCommerce\\WebhookController')) {
        paymos_set_webhook_client_factory(null);
    }

    unset($GLOBALS['paymos_test_wc_orders']);
}

function paymos_store_credentials(array $environments)
{
    PaymosWooCommerce\CredentialStore::save($environments);
    PaymosWooCommerce\Config::reset_cache();
}

/**
 * Open a private member for reflection across the whole declared PHP range.
 *
 * Before 8.1 the call is mandatory — reaching a private member without it
 * throws. From 8.5 it must NOT be made: the method is deprecated, and this
 * harness escalates deprecations to failures. One helper so neither end of
 * "Requires PHP: 7.4" through 8.5 is broken by fixing the other.
 *
 * @param ReflectionProperty|ReflectionMethod $reflector
 * @return ReflectionProperty|ReflectionMethod
 */
function paymos_reflect_open($reflector)
{
    if (PHP_VERSION_ID < 80100) {
        $reflector->setAccessible(true);
    }

    return $reflector;
}

function paymos_set_webhook_client_factory($factory)
{
    $property = paymos_reflect_open(
        new ReflectionProperty(PaymosWooCommerce\WebhookController::class, 'clientFactory')
    );
    $property->setValue(null, $factory);
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
