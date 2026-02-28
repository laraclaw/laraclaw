<?php

namespace LaraClaw\Channels\Contracts;

use LaraClaw\DTOs\Attachment;

interface SupportsAudio
{
    public function sendAudio(Attachment $attachment, ?string $caption = null): void;
}
