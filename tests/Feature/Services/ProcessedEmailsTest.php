<?php

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Log;
use Laraclaw\Enums\EmailClaim;
use Laraclaw\Services\ProcessedEmails;

beforeEach(function () {
    Log::spy();
});

function processedEmails(): ProcessedEmails
{
    return app(ProcessedEmails::class);
}

/**
 * Build a cache whose reads are always stale but whose add() is genuinely atomic.
 *
 * That is the shape of the race two pollers hit: both look and see nothing, and
 * only the store itself can break the tie. A claim built on get() then put() is
 * granted to both callers here, which is exactly the bug this guards.
 */
function racingCache(): Repository
{
    $store = new ArrayObject;

    $cache = Mockery::mock(Repository::class);
    $cache->allows('has')->andReturnFalse();
    $cache->allows('get')->andReturnNull();
    $cache->allows('put')->andReturnUsing(function (string $key, $value) use ($store): bool {
        $store[$key] = $value;

        return true;
    });
    $cache->allows('forget')->andReturnUsing(function (string $key) use ($store): bool {
        unset($store[$key]);

        return true;
    });
    $cache->allows('add')->andReturnUsing(function (string $key, $value) use ($store): bool {
        if ($store->offsetExists($key)) {
            return false;
        }

        $store[$key] = $value;

        return true;
    });
    $cache->allows('increment')->andReturnUsing(function (string $key) use ($store): int {
        return $store[$key] = ($store[$key] ?? 0) + 1;
    });

    return $cache;
}

it('grants a claim on the same email to only one caller at a time', function () {
    $identifier = '<race@example.com>';

    // The second caller stands in for a poller that was handed the same unseen
    // message before the first one got as far as queueing anything.
    expect(processedEmails()->claim($identifier))->toBe(EmailClaim::Granted)
        ->and(processedEmails()->claim($identifier))->toBe(EmailClaim::InFlight);
});

it('breaks a tie between two pollers whose reads both came back empty', function () {
    $processed = new ProcessedEmails(racingCache());
    $identifier = '<tie@example.com>';

    expect($processed->claim($identifier))->toBe(EmailClaim::Granted)
        ->and($processed->claim($identifier))->toBe(EmailClaim::InFlight);
});

it('lets the next poll retry after a failed handoff releases the lease', function () {
    $identifier = '<retry@example.com>';
    $processed = processedEmails();

    expect($processed->claim($identifier))->toBe(EmailClaim::Granted);

    $processed->release($identifier);

    expect($processed->claim($identifier))->toBe(EmailClaim::Granted);
});

it('refuses an email that was already handed off', function () {
    $identifier = '<done@example.com>';
    $processed = processedEmails();

    $processed->claim($identifier);
    $processed->confirm($identifier);
    $processed->release($identifier);

    expect($processed->claim($identifier))->toBe(EmailClaim::AlreadyHandled);
});

it('gives up once the attempt cap is passed', function () {
    config(['laraclaw.connectors.email.max_processing_attempts' => 2]);

    $identifier = '<broken@example.com>';
    $processed = processedEmails();

    foreach (range(1, 2) as $ignored) {
        expect($processed->claim($identifier))->toBe(EmailClaim::Granted);
        $processed->release($identifier);
    }

    expect($processed->claim($identifier))->toBe(EmailClaim::Exhausted);
});

it('keeps counting attempts across releases so a lease reset cannot loop forever', function () {
    config(['laraclaw.connectors.email.max_processing_attempts' => 1]);

    $identifier = '<loop@example.com>';
    $processed = processedEmails();

    expect($processed->claim($identifier))->toBe(EmailClaim::Granted);

    $processed->release($identifier);

    expect($processed->claim($identifier))->toBe(EmailClaim::Exhausted);
});
