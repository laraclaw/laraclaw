<?php

namespace LaraClaw\Channels;

abstract class Channel
{
    abstract public function identifier(): string;

    abstract public function send(string $message): void;

    public function userIdentifier(): ?string
    {
        return null;
    }

    protected function shouldRespond(?string $text = null): bool
    {
        return true;
    }
}
