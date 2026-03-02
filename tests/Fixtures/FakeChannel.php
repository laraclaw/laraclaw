<?php

namespace LaraClaw\Tests\Fixtures;

use Illuminate\Support\Collection;
use LaraClaw\Channels\Channel;
use LaraClaw\Message;

/**
 * Minimal in-memory channel for testing. Records sent messages for assertion.
 */
class FakeChannel extends Channel
{
    public readonly string $name;

    public array $sent = [];

    public function __construct(string $name = 'telegram')
    {
        $this->name = $name;
    }

    public function send(string $message): void
    {
        $this->sent[] = $message;
    }

    public function handleAttachments(Collection $attachments): void {}

    public function intercept(Message $message): bool
    {
        return false;
    }
}
