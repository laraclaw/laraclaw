<?php

namespace LaraClaw\Channels;

use Illuminate\Console\Command;
use LaraClaw\Channels\Contracts\SupportsConfirmation;

class TerminalChannel extends Channel implements SupportsConfirmation
{
    public function __construct(private Command $command) {}

    public function identifier(): string
    {
        return 'terminal:' . getmypid();
    }

    public function send(string $message): void
    {
        $this->command->info($message);
    }

    public function confirm(string $message, int $timeout = 120): bool
    {
        return $this->command->confirm("⚠️ {$message}");
    }
}
