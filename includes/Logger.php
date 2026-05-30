<?php

declare(strict_types=1);

namespace PaymosWooCommerce;

defined('ABSPATH') || exit;

final class Logger
{
    public static function info($message, array $context = array())
    {
        self::log('info', $message, $context);
    }

    public static function error($message, array $context = array())
    {
        self::log('error', $message, $context);
    }

    private static function log($level, $message, array $context)
    {
        if (Config::get('debug_logging', 'no') !== 'yes') {
            return;
        }

        if (!function_exists('wc_get_logger')) {
            return;
        }

        wc_get_logger()->log($level, self::redact($message), array(
            'source' => 'paymos',
            'context' => self::redactContext($context),
        ));
    }

    private static function redact($value)
    {
        return preg_replace('/(sk|pk|rk|whsec)_(test|live)_[A-Za-z0-9_-]+/', '$1_$2_[redacted]', (string) $value);
    }

    private static function redactContext(array $context)
    {
        $redacted = array();
        foreach ($context as $key => $value) {
            $redacted[$key] = is_scalar($value) ? self::redact((string) $value) : '[non-scalar]';
        }

        return $redacted;
    }
}

