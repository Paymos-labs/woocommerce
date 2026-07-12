<?php

declare(strict_types=1);

namespace PaymosWooCommerce;

defined('ABSPATH') || exit;

final class Config
{
    public const OPTION_KEY = 'woocommerce_paymos_settings';
    public const DEFAULT_BASE_URL = CredentialValidator::DEFAULT_BASE_URL;

    /** @var array<string, array<string, string>>|null */
    private static $credentials;

    /** @var string */
    private static $credentialError = '';

    /**
     * Presentation settings only. Secrets are deliberately excluded so they
     * can never leak into Checkout Blocks payment-method data.
     *
     * @return array<string, mixed>
     */
    public static function all()
    {
        return self::settings();
    }

    public static function get($key, $default = '')
    {
        $settings = self::settings();
        if (array_key_exists($key, $settings)) {
            return $settings[$key];
        }

        $config = self::environment_config();
        return array_key_exists($key, $config) ? $config[$key] : $default;
    }

    public static function mode()
    {
        $settings = self::settings();
        $mode = isset($settings['mode']) ? strtolower(trim((string) $settings['mode'])) : '';
        return in_array($mode, array('sandbox', 'live'), true) ? $mode : 'sandbox';
    }

    /**
     * @return array<string, string>
     */
    public static function environment_config($environment = null)
    {
        $environment = $environment === null ? self::mode() : strtolower(trim((string) $environment));
        if (!in_array($environment, array('sandbox', 'live'), true)) {
            return array();
        }

        $credentials = self::credentials();
        return isset($credentials[$environment]) && is_array($credentials[$environment])
            ? $credentials[$environment]
            : array();
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
            if (isset($config['webhook_secret']) && $config['webhook_secret'] !== '') {
                $secrets[$environment] = $config['webhook_secret'];
            }
        }

        return $secrets;
    }

    public static function masked_api_key($environment = null)
    {
        $config = self::environment_config($environment);
        $key = isset($config['api_key']) ? (string) $config['api_key'] : '';
        return self::mask($key);
    }

    public static function masked_project_id($environment = null)
    {
        $config = self::environment_config($environment);
        $projectId = isset($config['project_id']) ? (string) $config['project_id'] : '';
        return self::mask($projectId);
    }

    public static function credential_error()
    {
        self::credentials();
        return self::$credentialError;
    }

    public static function webhook_url()
    {
        return rest_url('paymos/v1/webhook');
    }

    public static function reset_cache()
    {
        self::$credentials = null;
        self::$credentialError = '';
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
     * @return array<string, array<string, string>>
     */
    private static function credentials()
    {
        if (self::$credentials !== null) {
            return self::$credentials;
        }

        try {
            self::$credentials = CredentialStore::load();
        } catch (\Throwable $exception) {
            self::$credentialError = $exception->getMessage();
            self::$credentials = array();
        }

        return self::$credentials;
    }

    private static function mask($value)
    {
        $value = (string) $value;
        if ($value === '') {
            return '';
        }
        if (strlen($value) <= 12) {
            return str_repeat('*', strlen($value));
        }

        return substr($value, 0, 8) . '...' . substr($value, -4);
    }
}
