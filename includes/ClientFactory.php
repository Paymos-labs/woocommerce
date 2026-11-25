<?php

declare(strict_types=1);

namespace PaymosWooCommerce;

use Paymos\Client;
use Paymos\ClientConfig;
use Paymos\Http\RequestDeadline;
use Paymos\Http\WordPressTransport;
use Paymos\Http\RetryingTransport;
use Paymos\Http\RetryPolicy;

defined('ABSPATH') || exit;

final class ClientFactory
{
    /**
     * Total callback-scoped verification budget across all reads, retries, and backoff
     * (BUG-1348). Leaves >= 4s margin for durable save and HTTP response inside the
     * server's 10s webhook delivery deadline without altering normal 30s SDK timeouts.
     */
    const WEBHOOK_BUDGET_SECONDS = 6.0;
    const WEBHOOK_ATTEMPT_TIMEOUT_SECONDS = 4;

    /**
     * @param array<string, mixed> $config
     * @return Client
     */
    public static function create(array $config)
    {
        $clientConfig = new ClientConfig(
            (string) $config['api_key'],
            (string) $config['api_secret'],
            (string) $config['base_url'],
            30
        );
        $transport = new RetryingTransport(
            new WordPressTransport(),
            RetryPolicy::default()
        );

        return new Client($clientConfig, $transport);
    }

    /**
     * Callback-bounded client sharing one cumulative deadline across all reverse-verification
     * and intent-recovery reads inside a single webhook delivery (BUG-1348).
     *
     * @param array<string, mixed> $config
     * @param RequestDeadline|null $deadline
     * @return Client
     */
    public static function createForWebhook(array $config, $deadline = null)
    {
        $effectiveDeadline = $deadline instanceof RequestDeadline
            ? $deadline
            : RequestDeadline::fromNow(self::WEBHOOK_BUDGET_SECONDS);

        $clientConfig = new ClientConfig(
            (string) $config['api_key'],
            (string) $config['api_secret'],
            (string) $config['base_url'],
            self::WEBHOOK_ATTEMPT_TIMEOUT_SECONDS
        );
        $transport = new RetryingTransport(
            new WordPressTransport(),
            new RetryPolicy(1, 150),
            $effectiveDeadline
        );

        return new Client($clientConfig, $transport);
    }
}
