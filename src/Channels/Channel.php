<?php

namespace LaraClaw\Channels;

use Illuminate\Support\Collection;

abstract class Channel
{
    abstract public function identifier(): string;

    abstract public function send(string $message): void;

    abstract public function handleAttachments(Collection $attachments): void;

    abstract public function userIdentifier(): ?string;

    abstract public function intercept(?string $text): bool;

    abstract public function shouldRespond(?string $text = null): bool;
}
