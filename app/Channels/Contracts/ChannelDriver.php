<?php

namespace App\Channels\Contracts;

use App\Channels\DTOs\Attachment;
use Illuminate\Support\Collection;

interface ChannelDriver
{
    /**
     * The platform identifier (e.g. "telegram", "slack").
     */
    public function platform(): string;

    /**
     * Unique conversation identifier on the platform.
     */
    public function conversationId(): string;

    /**
     * The sender's ID on the platform.
     */
    public function senderId(): string;

    /**
     * The message text, if any.
     */
    public function text(): ?string;

    /**
     * All attachments on the message.
     *
     * @return Collection<int, Attachment>
     */
    public function attachments(): Collection;

    /**
     * Send a text reply back through the same channel.
     */
    public function reply(string $text): void;
}
