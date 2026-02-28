<?php

namespace LaraClaw\Channels\Contracts;

use LaraClaw\DTOs\Attachment;

interface SupportsFiles
{
    public function sendFile(Attachment $attachment): void;
}
