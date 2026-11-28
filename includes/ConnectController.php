<?php

declare(strict_types=1);

namespace PaymosWooCommerce;

use Paymos\Connect\DeviceConnectClient;
use Paymos\Http\TransportInterface;
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
                'blocked' => __('Your browser blocked the approval tab.', 'paymos-for-woocommerce'),
                'openApproval' => __('Open the approval page', 'paymos-for-woocommerce'),
                'code' => __('Code:', 'paymos-for-woocommerce'),
            ),
        ));
    }

    public static function start()
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        self::authorizeAjax();
        try {
            $state = self::client()->start('woocommerce', self::sourceUrl(), self::settingsUrl());
            $flowId = ConnectStateStore::save($state);
            wp_send_json_success(array(
                'verification_url' => $state['verification_url'],
                'user_code' => $state['user_code'],
                'interval' => $state['interval'],
                'flow_id' => $flowId,
            ));
        } catch (\Throwable $exception) {
            wp_send_json_error(array('message' => $exception->getMessage()), 400);
        }
    }

    public static function poll()
    {
        check_ajax_referer(self::NONCE_ACTION, 'nonce');
        self::authorizeAjax();
        $flowId = isset($_POST['flow_id']) && is_string($_POST['flow_id'])
            ? sanitize_text_field(wp_unslash($_POST['flow_id'])) : '';
        // A generated hex token needs no normalization: never accept a different raw value.
        if ($flowId !== '' && ($_POST['flow_id'] !== $flowId || preg_match('/\A[a-f0-9]{32}\z/', $flowId) !== 1)) {
            $flowId = '';
        }
        try {
            $state = ConnectStateStore::load();
            if (!isset($state['device_code'], $state['flow_id']) || $flowId === ''
                || !hash_equals($state['flow_id'], $flowId)) {
                throw new \RuntimeException('No active Paymos connection request.');
            }

            $answer = (object) array('status' => 0);
            try {
                $result = self::client($answer)->poll((string) $state['device_code']);
            } catch (\Throwable $exception) {
                if (self::isFinalAnswer($answer->status)) {
                    // Paymos answered for good: end the request and show its reason.
                    throw $exception;
                }
                // A network or server failure says nothing about the device code: keep the request
                // and let the page poll again until Paymos answers or the request expires locally.
                wp_send_json_success(array('status' => 'retry', 'message' => $exception->getMessage()));
                return;
            }
            if ($result['status'] === 'connected') {
                if ($result['plugin'] !== 'woocommerce'
                    || rtrim((string) $result['source_url'], '/') !== self::sourceUrl()) {
                    throw new \RuntimeException('Paymos connection response does not match this store.');
                }
                if (!ConnectStateStore::complete($flowId, $result['credentials'])) {
                    throw new \RuntimeException('Paymos connection request was replaced.');
                }
                Config::reset_cache();
                wp_send_json_success(array('status' => 'connected'));
                return;
            }

            if ($result['status'] === 'authorization_pending' || $result['status'] === 'slow_down') {
                wp_send_json_success(array('status' => $result['status']));
                return;
            }

            ConnectStateStore::clear($flowId);
            wp_send_json_error(array('message' => 'Paymos connection was denied or expired.'), 409);
        } catch (\Throwable $exception) {
            try { ConnectStateStore::clear($flowId); } catch (\Throwable $cleanupError) { /* Preserve the original failure. */ }
            wp_send_json_error(array('message' => $exception->getMessage()), 400);
        }
    }

    private static function authorizeAjax()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'Access denied.'), 403);
        }
    }

    /**
     * @param \stdClass|null $answer Receives the HTTP status of the answer Paymos gave, 0 when none arrived.
     */
    private static function client(?\stdClass $answer = null)
    {
        $transport = new WordPressTransport();
        if ($answer !== null) {
            $transport = new class($transport, $answer) implements TransportInterface {
                private $inner;
                private $answer;

                public function __construct(TransportInterface $inner, \stdClass $answer)
                {
                    $this->inner = $inner;
                    $this->answer = $answer;
                }

                public function request($method, $url, array $headers, $body, $timeoutSeconds)
                {
                    $response = $this->inner->request($method, $url, $headers, $body, $timeoutSeconds);
                    $this->answer->status = $response->statusCode();

                    return $response;
                }
            };
        }

        return new DeviceConnectClient(self::CONNECT_BASE_URL, $transport, 15);
    }

    /**
     * Whether Paymos itself answered the poll: a success it could not use, or a
     * 4xx other than 408/429 (its reason, such as the webhook limit). Anything
     * else (no answer, 408, 429, 5xx) says nothing about the device code.
     */
    private static function isFinalAnswer(int $status): bool
    {
        return ($status >= 200 && $status < 300)
            || ($status >= 400 && $status < 500 && $status !== 408 && $status !== 429);
    }

    private static function sourceUrl()
    {
        return rtrim((string) home_url('/'), '/');
    }

    /**
     * Where approval should drop the merchant back. Paymos only honours it when it shares
     * an origin with the store URL above, so a site whose admin lives on another host simply
     * gets the Paymos confirmation screen instead of a redirect.
     */
    private static function settingsUrl()
    {
        return (string) admin_url('admin.php?page=wc-settings&tab=checkout&section=paymos');
    }
}
