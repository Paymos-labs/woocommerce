<?php

declare(strict_types=1);

namespace PaymosWooCommerce;

use Paymos\Client;
use Paymos\ClientConfig;
use Paymos\Http\WordPressTransport;
use Paymos\Http\RetryingTransport;
use Paymos\Http\RetryPolicy;

defined('ABSPATH') || exit;

final class ClientFactory
{
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
}
