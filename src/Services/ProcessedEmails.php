<?php

namespace Laraclaw\Services;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Log;

/**
 * Track which incoming emails have already been handed off for processing.
 *
 * IMAP gives us no transaction, so the seen flag and the work it guards can
 * always come apart. This keeps a record keyed on the RFC 822 Message-ID so a
 * crash between the two never gets the sender answered twice.
 */
class ProcessedEmails
{
    private const string KEY_PREFIX = 'laraclaw:email:processed:';

    /**
     * Inject the cache store that holds the deduplication records.
     */
    public function __construct(private readonly Repository $cache) {}

    /**
     * Record an attempt at this email and return whether the caller should process it.
     *
     * Returns false when the message was already handed off, or when it has
     * failed so often that retrying it forever is worse than dropping it.
     */
    public function claim(string $identifier): bool
    {
        $record = $this->record($identifier);

        if ($record['handed_off']) {
            return false;
        }

        $maxAttempts = (int) config('laraclaw.connectors.email.max_processing_attempts', 3);

        if ($record['attempts'] >= $maxAttempts) {
            Log::error('Laraclaw: email abandoned after repeated processing failures', [
                'identifier' => $identifier,
                'attempts' => $record['attempts'],
            ]);

            return false;
        }

        $record['attempts']++;

        $this->store($identifier, $record);

        return true;
    }

    /**
     * Mark the email as durably handed off so no later poll picks it up again.
     */
    public function confirm(string $identifier): void
    {
        $record = $this->record($identifier);
        $record['handed_off'] = true;

        $this->store($identifier, $record);
    }

    /**
     * Read the stored record for an email, falling back to a fresh one.
     *
     * @return array{attempts: int, handed_off: bool}
     */
    private function record(string $identifier): array
    {
        return $this->cache->get($this->key($identifier), [
            'attempts' => 0,
            'handed_off' => false,
        ]);
    }

    /**
     * Write the record back with the configured retention.
     *
     * @param  array{attempts: int, handed_off: bool}  $record
     */
    private function store(string $identifier, array $record): void
    {
        $this->cache->put(
            $this->key($identifier),
            $record,
            (int) config('laraclaw.connectors.email.processed_retention', 604800),
        );
    }

    /**
     * Hash the identifier so an arbitrarily long Message-ID still fits a cache key.
     */
    private function key(string $identifier): string
    {
        return self::KEY_PREFIX . md5($identifier);
    }
}
