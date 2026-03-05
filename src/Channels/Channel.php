<?php

namespace LaraClaw\Channels;

use Illuminate\Support\Collection;
use LaraClaw\Enums\ChannelType;
use LaraClaw\Models\Thread;

/**
 * Base class for all LaraClaw channel implementations.
 */
abstract class Channel
{
    /**
     * The channel type for this implementation.
     */
    abstract public ChannelType $type { get; }

    /**
     * Send a reply to the given thread, optionally with file attachments.
     */
    abstract public function reply(?Thread $thread, string $text, ?Collection $attachments = null);

}
