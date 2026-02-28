<?php

namespace LaraClaw\Channels;

use Illuminate\Support\Collection;

abstract class Channel
{
    abstract public function identifier(): string;

    abstract public function send(string $message): void;

    public function sendAttachments(Collection $attachments): void {}

    public function userIdentifier(): ?string
    {
        return null;
    }

    public function intercept(?string $text): bool
    {
        return false;
    }

    public function shouldRespond(?string $text = null): bool
    {
        return true;
    }
}
