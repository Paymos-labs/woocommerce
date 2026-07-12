<?php

declare(strict_types=1);

use Paymos\Http\WordPressTransport;

function test_wordpress_transport_uses_safe_http_api_without_redirects()
{
    paymos_reset_test_state();
    $captured = array();
    $GLOBALS['paymos_test_remote_handler'] = static function ($url, array $args) use (&$captured) {
        $captured = array('url' => $url, 'args' => $args);
        return array(
            'response' => array('code' => 201),
            'body' => '{"ok":true}',
            'headers' => array('content-type' => 'application/json'),
        );
    };

    $response = (new WordPressTransport())->request(
        'POST',
        'https://api.paymos.io/v1/invoices',
        array('Authorization' => 'Paymos-HMAC signed'),
        '{"amount":"10.00"}',
        30
    );

    assertSameValue(201, $response->statusCode(), 'WordPress transport must return the HTTP status.');
    assertSameValue('{"ok":true}', $response->body(), 'WordPress transport must return the response body.');
    assertSameValue(0, $captured['args']['redirection'], 'signed requests must never follow redirects.');
    assertSameValue(true, $captured['args']['sslverify'], 'TLS verification must stay enabled.');
    assertSameValue(true, $captured['args']['reject_unsafe_urls'], 'WordPress SSRF protection must stay enabled.');
    assertSameValue('Paymos-HMAC signed', $captured['args']['headers']['Authorization'], 'signed authorization header must be preserved.');
}

function test_wordpress_transport_fails_closed_on_wordpress_http_error()
{
    paymos_reset_test_state();
    $threw = false;

    try {
        (new WordPressTransport())->request('GET', 'https://api.paymos.io/v1/invoices/inv_1', array(), '', 30);
    } catch (RuntimeException $exception) {
        $threw = true;
    }

    assertTrueValue($threw, 'WordPress HTTP errors must fail closed.');
}
