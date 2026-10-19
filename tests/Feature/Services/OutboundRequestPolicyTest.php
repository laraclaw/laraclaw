<?php

use Laraclaw\Services\OutboundRequestPolicy;

it('pins the validated addresses on the https port even when the scheme is uppercased', function () {
    $options = (new OutboundRequestPolicy)->client('HTTPS://example.com/thing', 5)->getOptions();

    expect($options)->toHaveKey('curl');

    $entry = $options['curl'][CURLOPT_RESOLVE][0];

    // Without the lowercasing the default port falls through to 80, the pin
    // never matches the connection curl actually makes, and it resolves the
    // host again on its own.
    expect($entry)->toStartWith('example.com:443:');
})->skip(! defined('CURLOPT_RESOLVE'), 'curl extension not available');

it('pins the same port for a lowercase https URL', function () {
    $options = (new OutboundRequestPolicy)->client('https://example.com/thing', 5)->getOptions();

    expect($options['curl'][CURLOPT_RESOLVE][0])->toStartWith('example.com:443:');
})->skip(! defined('CURLOPT_RESOLVE'), 'curl extension not available');

it('honours an explicit port over the scheme default', function () {
    $options = (new OutboundRequestPolicy)->client('https://example.com:8443/thing', 5)->getOptions();

    expect($options['curl'][CURLOPT_RESOLVE][0])->toStartWith('example.com:8443:');
})->skip(! defined('CURLOPT_RESOLVE'), 'curl extension not available');
