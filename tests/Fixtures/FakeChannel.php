<?php

namespace LaraClaw\Tests\Fixtures;

use Illuminate\Support\Collection;
use LaraClaw\Channels\Channel;
use LaraClaw\Enums\ChannelType;
use LaraClaw\Models\Thread;

/**
 * Minimal in-memory channel for testing. Records sent messages for assertion.
 */
class FakeChannel extends Channel
{
    public ChannelType $type { get { return ChannelType::Telegram; } }

    public array $sent = [];

    public function reply(?Thread $thread, string $text, ?Collection $attachments = null): void
    {
        $this->sent[] = $text;
    }
}
