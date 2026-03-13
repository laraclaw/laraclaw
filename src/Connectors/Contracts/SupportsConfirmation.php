<?php

namespace LaraClaw\Connectors\Contracts;

use LaraClaw\DTOs\IncomingMessage;

interface SupportsConfirmation
{
    /**
     * Prompt the user for confirmation and return their response.
     */
    public function askForConfirmation(IncomingMessage $context, string $prompt, int $timeout = 120): bool;
}
