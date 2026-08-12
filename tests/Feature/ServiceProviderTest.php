<?php

use Laraclaw\LaraclawServiceProvider;
use Laraclaw\Services\Calendar\Contracts\CalendarDriver;

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

it('resolves no calendar driver when the driver is unset', function () {
    config(['laraclaw.tools.calendar_manager.driver' => null]);

    $provider = new LaraclawServiceProvider($this->app);
    $method = new ReflectionMethod($provider, 'registerCalendarDriver');
    $method->setAccessible(true);
    $method->invoke($provider);

    expect($this->app->make(CalendarDriver::class))->toBeNull();
});

it('resolves no calendar driver when the driver is an empty string', function () {
    // LARACLAW_CALENDAR_DRIVER= with no value yields '' rather than null, which
    // used to fall through to the throw and break every request.
    config(['laraclaw.tools.calendar_manager.driver' => '']);

    $provider = new LaraclawServiceProvider($this->app);
    $method = new ReflectionMethod($provider, 'registerCalendarDriver');
    $method->setAccessible(true);
    $method->invoke($provider);

    expect($this->app->make(CalendarDriver::class))->toBeNull();
});

it('throws on a calendar driver it does not recognise', function () {
    config(['laraclaw.tools.calendar_manager.driver' => 'outlook']);

    $provider = new LaraclawServiceProvider($this->app);
    $method = new ReflectionMethod($provider, 'registerCalendarDriver');
    $method->setAccessible(true);
    $method->invoke($provider);

    expect(fn () => $this->app->make(CalendarDriver::class))
        ->toThrow(RuntimeException::class, 'Unknown calendar driver: outlook');
});
