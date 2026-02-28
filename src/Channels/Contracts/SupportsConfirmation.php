<?php

namespace LaraClaw\Channels\Contracts;

use LaraClaw\Message;

interface SupportsConfirmation
{
    /**
     * Prompt the user for confirmation and return their response.
     */
    public function confirm(Message $context, string $prompt, int $timeout = 120): bool;
}
