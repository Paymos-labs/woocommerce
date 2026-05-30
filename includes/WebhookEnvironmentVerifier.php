<?php

declare(strict_types=1);

namespace PaymosWooCommerce;

use Paymos\Webhook\EventStoreInterface;
use Paymos\Webhook\MultiEnvironmentWebhookVerifier;

defined('ABSPATH') || exit;

final class WebhookEnvironmentVerifier
{
    /** @var MultiEnvironmentWebhookVerifier */
    private $inner;

    /**
     * @param array<string, string> $secrets
     */
    public function __construct(array $secrets, EventStoreInterface $eventStore, $dedupTtlSeconds = 604800)
    {
        $this->inner = new MultiEnvironmentWebhookVerifier($secrets, $eventStore, 300, $dedupTtlSeconds);
    }

    /**
     * @return array{environment: string, event: array<string, mixed>}
     */
    public function process($signatureHeader, $rawBody, $now = null)
    {
        $verified = $this->inner->process($signatureHeader, $rawBody, $now);

        return array(
            'environment' => $verified->environment(),
            'event' => $verified->event()->toArray(),
        );
    }
}
