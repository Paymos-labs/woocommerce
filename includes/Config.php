<?php

declare(strict_types=1);

namespace PaymosWooCommerce;

defined('ABSPATH') || exit;

final class Config
{
    public const OPTION_KEY = 'woocommerce_paymos_settings';
    public const DEFAULT_BASE_URL = 'https://api.paymos.io';

    /** @var array<string, mixed>|null */
    private static $generated;

    /**
     * @return array<string, mixed>
     */
    public static function all()
    {
        return array_merge(self::settings(), self::generated());
    }

    public static function get($key, $default = '')
    {
        $config = self::environment_config();
        if (!array_key_exists($key, $config)) {
            $config = self::all();
        }

        return array_key_exists($key, $config) ? $config[$key] : $default;
    }

    public static function mode()
    {
        $settings = self::settings();
        $mode = isset($settings['mode']) ? (string) $settings['mode'] : '';

        if ($mode === '' && isset($settings['environment'])) {
            $mode = (string) $settings['environment'];
        }

        $mode = strtolower(trim($mode));
        return in_array($mode, array('sandbox', 'live'), true) ? $mode : 'sandbox';
    }

    /**
     * @return array<string, mixed>
     */
    public static function environment_config($environment = null)
    {
        $environment = $environment === null ? self::mode() : strtolower(trim((string) $environment));
        if (!in_array($environment, array('sandbox', 'live'), true)) {
            return array();
        }

        $generated = self::generated();
        if (isset($generated['environments']) && is_array($generated['environments'])) {
            $configs = $generated['environments'];
            if (isset($configs[$environment]) && is_array($configs[$environment])) {
                return self::with_defaults($configs[$environment]);
            }

            return array();
        }

        $flat = array_merge(self::settings(), $generated);
        if (count($flat) === 0) {
            return array();
        }

        $flatEnvironment = isset($flat['environment']) ? strtolower(trim((string) $flat['environment'])) : 'live';
        if (!in_array($flatEnvironment, array('sandbox', 'live'), true)) {
            $flatEnvironment = 'live';
        }

        if ($flatEnvironment !== $environment) {
            return array();
        }

        return self::with_defaults($flat);
    }

    public static function has_environment($environment)
    {
        return count(self::environment_config($environment)) > 0;
    }

    /**
     * @return array<string, string>
     */
    public static function webhook_secrets()
    {
        $secrets = array();

        foreach (array('sandbox', 'live') as $environment) {
            $config = self::environment_config($environment);
            if (isset($config['webhook_secret']) && is_scalar($config['webhook_secret']) && trim((string) $config['webhook_secret']) !== '') {
                $secrets[$environment] = (string) $config['webhook_secret'];
            }
        }

        return $secrets;
    }

    public static function masked_api_key($environment = null)
    {
        $config = self::environment_config($environment);
        $key = isset($config['api_key']) && is_scalar($config['api_key']) ? (string) $config['api_key'] : '';
        if ($key === '') {
            return '';
        }

        if (strlen($key) <= 12) {
            return str_repeat('*', strlen($key));
        }

        return substr($key, 0, 8) . '...' . substr($key, -4);
    }

    public static function webhook_url()
    {
        return rest_url('paymos/v1/webhook');
    }

    public static function reset_for_tests()
    {
        self::$generated = null;
    }

    /**
     * @return array<string, mixed>
     */
    private static function settings()
    {
        $settings = get_option(self::OPTION_KEY, array());
        return is_array($settings) ? $settings : array();
    }

    /**
     * @return array<string, mixed>
     */
    private static function generated()
    {
        if (self::$generated !== null) {
            return self::$generated;
        }

        $file = PAYMOS_WC_PLUGIN_DIR . 'paymos-config.php';
        if (!is_readable($file)) {
            self::$generated = array();
            return self::$generated;
        }

        $config = require $file;
        self::$generated = is_array($config) ? $config : array();
        return self::$generated;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private static function with_defaults(array $config)
    {
        if (!isset($config['base_url']) || !is_scalar($config['base_url']) || trim((string) $config['base_url']) === '') {
            $config['base_url'] = self::DEFAULT_BASE_URL;
        }

        return $config;
    }
}
