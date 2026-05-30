<?php

declare(strict_types=1);

use Paymos\Exception\DuplicateEventException;
use Paymos\Exception\SignatureMismatchException;
use PaymosWooCommerce\WebhookEnvironmentVerifier;
use Paymos\Webhook\EventStoreInterface;

final class FakeEventStore implements EventStoreInterface
{
    /** @var array<string, bool> */
    private $events = array();

    public function remember($eventId, $ttlSeconds)
    {
        $key = (string) $eventId;
        if (isset($this->events[$key])) {
            return false;
        }

        $this->events[$key] = true;
        return true;
    }
}

function paymos_signed_header($secret, $body, $timestamp = 1709000000)
{
    return 't=' . $timestamp . ',v1=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
}

function test_webhook_environment_verifier_matches_sandbox_secret()
{
    $body = json_encode(array(
        'event_id' => 'evt_sandbox',
        'event_type' => 'invoice.paid',
        'data' => array('is_test' => true),
    ));
    $verifier = new WebhookEnvironmentVerifier(array(
        'sandbox' => 'whsec_test',
        'live' => 'whsec_live',
    ), new FakeEventStore());

    $result = $verifier->process(paymos_signed_header('whsec_test', $body), $body, 1709000000);

    assertSameValue('sandbox', $result['environment'], 'sandbox signature must authenticate sandbox environment.');
    assertSameValue('evt_sandbox', $result['event']['event_id'], 'verified event must be returned.');
}

function test_webhook_environment_verifier_matches_live_secret()
{
    $body = json_encode(array(
        'event_id' => 'evt_live',
        'event_type' => 'invoice.paid',
        'data' => array('is_test' => false),
    ));
    $verifier = new WebhookEnvironmentVerifier(array(
        'sandbox' => 'whsec_test',
        'live' => 'whsec_live',
    ), new FakeEventStore());

    $result = $verifier->process(paymos_signed_header('whsec_live', $body), $body, 1709000000);

    assertSameValue('live', $result['environment'], 'live signature must authenticate live environment.');
}

function test_webhook_environment_verifier_rejects_unknown_secret()
{
    $body = json_encode(array('event_id' => 'evt_bad', 'event_type' => 'invoice.paid'));
    $verifier = new WebhookEnvironmentVerifier(array(
        'sandbox' => 'whsec_test',
        'live' => 'whsec_live',
    ), new FakeEventStore());

    try {
        $verifier->process(paymos_signed_header('whsec_other', $body), $body, 1709000000);
    } catch (SignatureMismatchException $e) {
        assertTrueValue(true, 'unknown signature must be rejected.');
        return;
    }

    throw new RuntimeException('unknown signature must throw SignatureMismatchException.');
}

function test_webhook_environment_verifier_deduplicates_event_id()
{
    $body = json_encode(array('event_id' => 'evt_duplicate', 'event_type' => 'invoice.paid'));
    $store = new FakeEventStore();
    $verifier = new WebhookEnvironmentVerifier(array('live' => 'whsec_live'), $store);
    $signature = paymos_signed_header('whsec_live', $body);

    $verifier->process($signature, $body, 1709000000);

    try {
        $verifier->process($signature, $body, 1709000000);
    } catch (DuplicateEventException $e) {
        assertTrueValue(true, 'duplicate event id must be rejected as duplicate.');
        return;
    }

    throw new RuntimeException('duplicate event id must throw DuplicateEventException.');
}

