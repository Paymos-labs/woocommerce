<?php

declare(strict_types=1);

namespace PaymosWooCommerce;

use Paymos\Connect\DeviceConnectClient;
use Paymos\Http\WordPressTransport;

defined('ABSPATH') || exit;

final class ConnectController
{
    public const START_ACTION = 'paymos_woocommerce_connect_start';
    public const POLL_ACTION = 'paymos_woocommerce_connect_poll';
    public const NONCE_ACTION = 'paymos_woocommerce_connect';
    private const CONNECT_BASE_URL = 'https://app.paymos.io';

    public static function register()
    {
        add_action('wp_ajax_' . self::START_ACTION, array(__CLASS__, 'start'));
        add_action('wp_ajax_' . self::POLL_ACTION, array(__CLASS__, 'poll'));
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));
    }

    public static function enqueue_assets()
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        wp_enqueue_script(
            'paymos-woocommerce-connect',
            plugins_url('assets/js/connect.js', PAYMOS_WC_PLUGIN_FILE),
            array(),
            PAYMOS_WC_PLUGIN_VERSION,
            true
        );
        wp_localize_script('paymos-woocommerce-connect', 'PaymosWooConnect', array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::NONCE_ACTION),
            'startAction' => self::START_ACTION,
            'pollAction' => self::POLL_ACTION,
            'messages' => array(
                'starting' => __('Starting secure connection…', 'paymos-for-woocommerce'),
                'waiting' => __('Waiting for approval in Paymos…', 'paymos-for-woocommerce'),
                'connected' => __('Paymos connected. Reloading settings…', 'paymos-for-woocommerce'),
                'failed' => __('Paymos connection failed.', 'paymos-for-woocommerce'),
                'popup' => __('Allow pop-ups for this page and try again.', 'paymos-for-woocommerce'),
            ),
        ));
    }

    public static function start()
    {
        self::authorizeAjax();
        try {
            $state = self::client()->start('woocommerce', self::sourceUrl());
            ConnectStateStore::save($state);
            wp_send_json_success(array(
                'verification_url' => $state['verification_url'],
                'user_code' => $state['user_code'],
                'interval' => $state['interval'],
            ));
        } catch (\Throwable $exception) {
            wp_send_json_error(array('message' => $exception->getMessage()), 400);
        }
    }

    public static function poll()
    {
        self::authorizeAjax();
        try {
            $state = ConnectStateStore::load();
            if (!isset($state['device_code'])) {
                throw new \RuntimeException('No active Paymos connection request.');
            }

            $result = self::client()->poll((string) $state['device_code']);
            if ($result['status'] === 'connected') {
                if ($result['plugin'] !== 'woocommerce'
                    || rtrim((string) $result['source_url'], '/') !== self::sourceUrl()) {
                    throw new \RuntimeException('Paymos connection response does not match this store.');
                }
                CredentialStore::save($result['credentials']);
                ConnectStateStore::clear();
                Config::reset_cache();
                wp_send_json_success(array('status' => 'connected'));
            }

            if ($result['status'] === 'authorization_pending' || $result['status'] === 'slow_down') {
                wp_send_json_success(array('status' => $result['status']));
            }

            ConnectStateStore::clear();
            wp_send_json_error(array('message' => 'Paymos connection was denied or expired.'), 409);
        } catch (\Throwable $exception) {
            ConnectStateStore::clear();
            wp_send_json_error(array('message' => $exception->getMessage()), 400);
        }
    }

    private static function authorizeAjax()
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Access denied.'), 403);
        }
    }

    private static function client()
    {
        return new DeviceConnectClient(self::CONNECT_BASE_URL, new WordPressTransport(), 15);
    }

    private static function sourceUrl()
    {
        return rtrim((string) home_url('/'), '/');
    }
}
