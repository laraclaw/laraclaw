<?php

namespace LaraClaw\Connectors;

use Illuminate\Support\Collection;
use LaraClaw\Enums\ConnectorType;
use LaraClaw\Models\Thread;

/**
 * Base class for all LaraClaw connector implementations.
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
    abstract public function reply(?Thread $thread, string $text, ?Collection $attachments = null);

    /**
     * Determine if the given key represents a direct message conversation.
     */
    abstract public static function isDirectMessage(string $key): bool;
}
