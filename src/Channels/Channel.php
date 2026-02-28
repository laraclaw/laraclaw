<?php

namespace LaraClaw\Channels;

use Illuminate\Support\Collection;
use LaraClaw\Message;

abstract class Channel
{
    abstract public readonly string $name;

    abstract public function send(string $message): void;

    abstract public function handleAttachments(Collection $attachments): void;

    abstract public function intercept(Message $message): bool;
}
