<?php

namespace LaraClaw\Commands;

class CommandRegistry
{
    /** @var array<string, Command> */
    private array $commands = [];

    public function register(Command $command): void
    {
        $this->commands[strtolower($command->prefix())] = $command;
    }

    /**
     * Match input text against registered commands.
     * Returns the matched Command, or null if no match.
     */
    public function match(string $text): ?Command
    {
        $normalized = strtolower(trim($text));

        foreach ($this->commands as $prefix => $command) {
            if ($normalized === $prefix || str_starts_with($normalized, $prefix . ' ')) {
                return $command;
            }
        }

        return null;
    }
}
