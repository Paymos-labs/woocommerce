<?php

declare(strict_types=1);

namespace PaymosWooCommerce;

use Paymos\Exception\DuplicateEventException;
use Paymos\Exception\SignatureMismatchException;
use Paymos\Exception\TimestampSkewException;
use Paymos\Plugin\InvoiceReverseVerifier;
use Paymos\Plugin\StatusMapper;
use Paymos\Webhook\MultiEnvironmentWebhookVerifier;
use Paymos\Webhook\WebhookEvent;

defined('ABSPATH') || exit;

final class WebhookController
{
    /** @var callable|null */
    private static $clientFactory;

    public static function register_routes()
    {
        register_rest_route('paymos/v1', '/webhook', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'handle'),
            'permission_callback' => '__return_true',
        ));
    }

    public static function handle(\WP_REST_Request $request)
    {
        $secrets = Config::webhook_secrets();
        if (count($secrets) === 0) {
            Logger::error('Paymos webhook received but webhook secrets are not configured.');
            return new \WP_REST_Response(array('error' => 'not_configured'), 500);
        }

        $rawBody = $request->get_body();
        $signature = $request->get_header('x-webhook-signature');

        $eventStore = new EventStore();

        try {
            $verified = (new MultiEnvironmentWebhookVerifier($secrets, $eventStore))->process($signature, $rawBody);
            $environment = $verified->environment();
            $eventObject = $verified->event();
            $event = $eventObject->toArray();
            Logger::info('Paymos webhook verified.', array(
                'environment' => $environment,
                'event_id' => $eventObject->id(),
                'event_type' => $eventObject->type(),
            ));

            if (!self::isInvoiceEvent($event)) {
                Logger::info('Paymos non-invoice webhook ignored.', array(
                    'environment' => $environment,
                    'event_type' => isset($event['event_type']) && is_scalar($event['event_type']) ? (string) $event['event_type'] : '',
                ));
                $eventStore->commit();
                return new \WP_REST_Response(array('ok' => true, 'ignored' => true), 200);
            }

            self::assertPayloadEnvironment($eventObject, $environment);
            self::applyEvent($eventObject, $environment);
            $eventStore->commit();
        } catch (DuplicateEventException $e) {
            Logger::info('Paymos duplicate webhook ignored.');
            return new \WP_REST_Response(array('ok' => true, 'duplicate' => true), 200);
        } catch (SignatureMismatchException $e) {
            Logger::error('Paymos webhook signature mismatch.');
            return new \WP_REST_Response(array('error' => 'bad_signature'), 401);
        } catch (TimestampSkewException $e) {
            Logger::error('Paymos webhook timestamp skew.');
            return new \WP_REST_Response(array('error' => 'bad_timestamp'), 401);
        } catch (\RuntimeException $e) {
            $eventStore->release();
            Logger::error('Paymos webhook processing failed: ' . $e->getMessage());
            return new \WP_REST_Response(array('error' => 'processing_failed'), 400);
        }

        return new \WP_REST_Response(array('ok' => true), 200);
    }

    private static function applyEvent(WebhookEvent $eventObject, $environment)
    {
        $event = $eventObject->toArray();
        $externalOrderId = $eventObject->externalOrderId();
        if ($externalOrderId === '') {
            throw new \RuntimeException('Paymos webhook payload is missing data.order.external_id.');
        }

        if (!preg_match('/^wc_([1-9][0-9]*)(?:_|$)/', $externalOrderId, $matches)) {
            throw new \RuntimeException('Paymos external order id has an invalid format.');
        }

        $order = wc_get_order((int) $matches[1]);
        if (!$order) {
            throw new \RuntimeException('Woo order for Paymos external order id was not found.');
        }
        $storedExternalOrderId = method_exists($order, 'get_meta')
            ? (string) $order->get_meta('_paymos_external_order_id', true)
            : '';
        if ($storedExternalOrderId === '' || !hash_equals($storedExternalOrderId, $externalOrderId)) {
            throw new \RuntimeException('Paymos external order id does not match the Woo order.');
        }

        self::assertOrderMatchesEvent($order, $event, $environment);
        self::reverseVerifyTerminalEvent($eventObject, $order, $environment);

        $mapper = new OrderMapper();
        $mapper->apply($order, $event);

        Logger::info('Paymos webhook applied to Woo order.', array(
            'external_order_id' => $externalOrderId,
            'environment' => (string) $environment,
            'event_type' => isset($event['event_type']) && is_scalar($event['event_type']) ? (string) $event['event_type'] : '',
        ));
    }

    /**
     * @param array<string, mixed> $event
     */
    private static function isInvoiceEvent(array $event)
    {
        $eventType = isset($event['event_type']) && is_scalar($event['event_type']) ? strtolower((string) $event['event_type']) : '';
        return strpos($eventType, 'invoice.') === 0;
    }

    private static function assertPayloadEnvironment(WebhookEvent $event, $environment)
    {
        $isTest = $event->isTest();
        if ($isTest === null) {
            return;
        }

        if ($environment === 'sandbox' && $isTest !== true) {
            throw new \RuntimeException('Sandbox webhook payload is not marked as test.');
        }

        if ($environment === 'live' && $isTest !== false) {
            throw new \RuntimeException('Live webhook payload is marked as test.');
        }
    }

    /**
     * @param array<string, mixed> $event
     */
    private static function assertOrderMatchesEvent($order, array $event, $environment)
    {
        $orderEnvironment = method_exists($order, 'get_meta') ? (string) $order->get_meta('_paymos_environment', true) : '';
        if ($orderEnvironment !== '' && $orderEnvironment !== (string) $environment) {
            throw new \RuntimeException('Paymos webhook environment does not match Woo order environment.');
        }

        $orderProjectId = method_exists($order, 'get_meta') ? (string) $order->get_meta('_paymos_project_id', true) : '';
        $eventProjectId = '';
        if (isset($event['data']['project_id']) && is_scalar($event['data']['project_id'])) {
            $eventProjectId = (string) $event['data']['project_id'];
        }

        if ($orderProjectId !== '' && $eventProjectId !== '' && $orderProjectId !== $eventProjectId) {
            throw new \RuntimeException('Paymos webhook project does not match Woo order project.');
        }
    }

    private static function reverseVerifyTerminalEvent(WebhookEvent $event, $order, $environment)
    {
        if (!self::requiresReverseVerify($event)) {
            return;
        }

        $result = (new InvoiceReverseVerifier(self::client($environment)))->verify($event, array(
            'project_id' => self::orderMeta($order, '_paymos_project_id'),
            'external_order_id' => $event->externalOrderId(),
            'amount' => self::orderMeta($order, '_paymos_invoice_amount'),
            'currency' => self::orderMeta($order, '_paymos_invoice_currency'),
        ));

        if (!$result->isVerified()) {
            throw new \RuntimeException('Paymos reverse invoice verification failed.');
        }
    }

    private static function requiresReverseVerify(WebhookEvent $event)
    {
        $action = StatusMapper::invoiceAction($event->type(), $event->status());
        return in_array($action, array(
            StatusMapper::ACTION_PAYMENT_COMPLETE,
            StatusMapper::ACTION_FAIL_ORDER,
            StatusMapper::ACTION_CANCEL_ORDER,
        ), true);
    }

    private static function client($environment)
    {
        if (self::$clientFactory !== null) {
            return call_user_func(self::$clientFactory, $environment);
        }

        $config = Config::environment_config($environment);
        foreach (array('api_key', 'api_secret', 'base_url') as $required) {
            if (!isset($config[$required]) || !is_scalar($config[$required]) || trim((string) $config[$required]) === '') {
                throw new \RuntimeException('Paymos credentials are incomplete.');
            }
        }

        return ClientFactory::create($config);
    }

    private static function orderMeta($order, $key)
    {
        if (!method_exists($order, 'get_meta')) {
            return '';
        }

        return (string) $order->get_meta($key, true);
    }

}
