<?php

namespace LaraClaw;

use Illuminate\Support\Collection;
use LaraClaw\Channels\Channel;

class Message
{
    public function __construct(
        public readonly Channel $channel,
        public readonly ?string $text = null,
        public readonly Collection $attachments = new Collection,
    ) {}
}
