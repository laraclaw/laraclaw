<?php

namespace LaraClaw\Channels\Contracts;

use LaraClaw\DTOs\Attachment;

interface SupportsImages
{
    public function sendImage(Attachment $attachment): void;
}
