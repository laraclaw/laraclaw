<?php

use LaraClaw\LaraclawServiceProvider;

it('throws when Telegram is enabled but admin_user_id is not set', function () {
    config(['laraclaw.connectors.telegram.enabled' => true]);
    config(['laraclaw.auth.admin_user_id' => null]);

    $provider = new LaraclawServiceProvider($this->app);

    $method = new ReflectionMethod($provider, 'validateConfiguration');
    $method->setAccessible(true);

    expect(fn () => $method->invoke($provider))
        ->toThrow(RuntimeException::class, 'LARACLAW_ADMIN_USER_ID');
});

it('throws when Slack is enabled but admin_user_id is not set', function () {
    config(['laraclaw.connectors.slack.enabled' => true]);
    config(['laraclaw.auth.admin_user_id' => null]);

    $provider = new LaraclawServiceProvider($this->app);

    $method = new ReflectionMethod($provider, 'validateConfiguration');
    $method->setAccessible(true);

    expect(fn () => $method->invoke($provider))
        ->toThrow(RuntimeException::class, 'LARACLAW_ADMIN_USER_ID');
});

it('does not throw when neither Telegram nor Slack is enabled and admin_user_id is absent', function () {
    config(['laraclaw.connectors.telegram.enabled' => false]);
    config(['laraclaw.connectors.slack.enabled' => false]);
    config(['laraclaw.auth.admin_user_id' => null]);

    $provider = new LaraclawServiceProvider($this->app);

    $method = new ReflectionMethod($provider, 'validateConfiguration');
    $method->setAccessible(true);

    expect(fn () => $method->invoke($provider))->not->toThrow(RuntimeException::class);
});

it('does not throw when admin_user_id is set and Telegram is enabled', function () {
    config(['laraclaw.connectors.telegram.enabled' => true]);
    config(['laraclaw.auth.admin_user_id' => 1]);

    $provider = new LaraclawServiceProvider($this->app);

    $method = new ReflectionMethod($provider, 'validateConfiguration');
    $method->setAccessible(true);

    expect(fn () => $method->invoke($provider))->not->toThrow(RuntimeException::class);
});
