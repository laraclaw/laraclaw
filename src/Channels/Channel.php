<?php

namespace LaraClaw\Channels;

use Illuminate\Support\Collection;
use LaraClaw\DTOs\Attachment;

abstract class Channel
{
    protected ?string $messageText = null;

    /** @var Collection<int, Attachment> */
    protected Collection $messageAttachments;

    abstract public function identifier(): string;

    abstract public function send(string $message): void;

    public function text(): ?string
    {
        return $this->messageText;
    }

    /** @return Collection<int, Attachment> */
    public function attachments(): Collection
    {
        return $this->messageAttachments ??= collect();
    }

    public function userIdentifier(): ?string
    {
        return null;
    }

    public function shouldRespond(): bool
    {
        return true;
    }
}
