<?php

namespace LaraClaw\Channels;

use Illuminate\Support\Collection;

abstract class Channel
{
    abstract public readonly string $name;

    abstract public function conversationKey(): string;

    abstract public function conversationIsDirectMessage(): bool;

    abstract public function send(string $message): void;

    abstract public function handleAttachments(Collection $attachments): void;

    abstract public function intercept(?string $text): bool;
}
