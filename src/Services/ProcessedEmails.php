<?php

namespace Laraclaw\Services;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Log;
use Laraclaw\Enums\EmailClaim;

/**
 * Track which incoming emails have already been handed off for processing.
 *
 * IMAP gives us no transaction, so the seen flag and the work it guards can
 * always come apart. This keeps a record keyed on the RFC 822 Message-ID so a
 * crash between the two never gets the sender answered twice.
 */
class ProcessedEmails
{
    private const string KEY_PREFIX = 'laraclaw:email:';

    /**
     * Inject the cache store that holds the deduplication records.
     */
    public function __construct(private readonly Repository $cache) {}

    /**
     * Try to take ownership of an email and report whether the caller may process it.
     *
     * Two pollers can be handed the same unseen message at the same time, so the
     * lease is taken with add(), which every cache store implements as a single
     * atomic operation. Only the process that creates the lease goes on to queue
     * the agent, and the loser is told to leave the message alone.
     */
    public function claim(string $identifier): EmailClaim
    {
        if ($this->cache->has($this->key('handled', $identifier))) {
            return EmailClaim::AlreadyHandled;
        }

        if (! $this->cache->add($this->key('lease', $identifier), true, $this->leaseSeconds())) {
            return EmailClaim::InFlight;
        }

        $attempts = $this->recordAttempt($identifier);
        $maxAttempts = (int) config('laraclaw.connectors.email.max_processing_attempts', 3);

        if ($attempts > $maxAttempts) {
            Log::error('Laraclaw: email abandoned after repeated processing failures', [
                'identifier' => $identifier,
                'attempts' => $attempts,
            ]);

            return EmailClaim::Exhausted;
        }

        return EmailClaim::Granted;
    }

    /**
     * Mark the email as durably handed off so no later poll picks it up again.
     */
    public function confirm(string $identifier): void
    {
        $this->cache->put($this->key('handled', $identifier), true, $this->retentionSeconds());
    }

    /**
     * Drop the lease after a failed handoff so the next poll can retry right away.
     *
     * The attempt counter is deliberately left alone. It is what stops an email
     * that fails every single time from being retried forever.
     */
    public function release(string $identifier): void
    {
        $this->cache->forget($this->key('lease', $identifier));
    }

    /**
     * Count this attempt and return the new total.
     *
     * add() seeds the counter only when it is missing, and increment() is atomic,
     * so parallel workers cannot lose an attempt between them.
     */
    private function recordAttempt(string $identifier): int
    {
        $key = $this->key('attempts', $identifier);

        if ($this->cache->add($key, 1, $this->retentionSeconds())) {
            return 1;
        }

        return (int) $this->cache->increment($key);
    }

    /**
     * Return how long a crashed attempt blocks a retry, in seconds.
     */
    private function leaseSeconds(): int
    {
        return (int) config('laraclaw.connectors.email.processing_lease', 300);
    }

    /**
     * Return how long the record of a processed email is kept, in seconds.
     */
    private function retentionSeconds(): int
    {
        return (int) config('laraclaw.connectors.email.processed_retention', 604800);
    }

    /**
     * Hash the identifier so an arbitrarily long Message-ID still fits a cache key.
     */
    private function key(string $bucket, string $identifier): string
    {
        return self::KEY_PREFIX . $bucket . ':' . md5($identifier);
    }
}
