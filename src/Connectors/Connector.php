<?php

namespace Laraclaw\Connectors;

use Illuminate\Support\Collection;
use Laraclaw\Enums\ConnectorType;
use Laraclaw\Models\Thread;

/**
 * Base class for all Laraclaw connector implementations.
 */
abstract class Connector
{
    /**
     * The connector type for this implementation.
     */
    abstract public ConnectorType $type { get; }

    /**
     * Send a reply to the given thread, optionally with file attachments.
     */
    abstract public function reply(?Thread $thread, string $text, ?Collection $attachments = null): void;

    /**
     * Determine if the given key represents a direct message conversation.
     */
    abstract public static function isDirectMessage(string $key): bool;
}
