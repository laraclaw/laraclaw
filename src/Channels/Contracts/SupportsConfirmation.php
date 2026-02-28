<?php

namespace LaraClaw\Channels\Contracts;

interface SupportsConfirmation
{
    /**
     * Prompt the user for confirmation and return their response.
     *
     * @param  string  $message
     * @param  int  $timeout
     * @return bool
     */
    public function confirm(string $message, int $timeout = 120): bool;
}
