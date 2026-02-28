<?php

namespace LaraClaw\Channels\Contracts;

interface SupportsConfirmation
{
    public function confirm(string $message, int $timeout = 120): bool;
}
