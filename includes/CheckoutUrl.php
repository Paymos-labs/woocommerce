<?php

declare(strict_types=1);

namespace PaymosWooCommerce;

defined('ABSPATH') || exit;

final class CheckoutUrl
{
    public static function isTrusted($url)
    {
        if (!is_string($url) || preg_match('/[\x00-\x20\x7f]/', $url)) {
            return false;
        }
        $parts = wp_parse_url($url);
        // CmsChannelProfile uses AppOptions.CheckoutBaseUrl: production and the documented shared node.
        return is_array($parts) && isset($parts['scheme'], $parts['host'])
            && strtolower($parts['scheme']) === 'https'
            && in_array(strtolower($parts['host']), array('checkout.paymos.io', 'checkout-local.paymos.io'), true)
            && !isset($parts['user']) && !isset($parts['pass'])
            && (!isset($parts['port']) || $parts['port'] === 443);
    }
}
