<?php

declare(strict_types=1);

namespace PaymosWooCommerce;

defined('ABSPATH') || exit;

final class Autoloader
{
    public static function register()
    {
        spl_autoload_register(array(__CLASS__, 'load'));
        self::registerSdk();
    }

    public static function load($class)
    {
        $prefix = __NAMESPACE__ . '\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }

        $relative = substr($class, strlen($prefix));
        $file = PAYMOS_WC_PLUGIN_DIR . 'includes/' . str_replace('\\', '/', $relative) . '.php';
        if (is_readable($file)) {
            require_once $file;
        }
    }

    private static function registerSdk()
    {
        $sdkAutoload = PAYMOS_WC_PLUGIN_DIR . 'vendor/autoload.php';
        if (is_readable($sdkAutoload)) {
            require_once $sdkAutoload;
        }
    }
}

