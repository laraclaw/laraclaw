<?php

namespace LaraClaw\Channels;

use Illuminate\Support\Collection;
use LaraClaw\Message;

/**
 * Base class for all LaraClaw channel implementations.
 */
abstract class Channel
{
    /**
     * Unique identifier string for this channel (e.g. "telegram", "slack").
     */
    abstract public readonly string $name;

    /**
     * Send a text message to the channel.
     */
    abstract public function send(string $message): void;

    /**
     * Deliver any pending file attachments to the channel.
     */
    abstract public function handleAttachments(Collection $attachments): void;

    /**
     * Optionally intercept an inbound message (e.g. a confirmation reply).
     * Returns true if the message was consumed and should not be processed further.
     */
    abstract public function intercept(Message $message): bool;
}
