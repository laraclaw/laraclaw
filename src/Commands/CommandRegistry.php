<?php

namespace LaraClaw\Commands;

/**
 * Holds registered chat commands and matches inbound text against them.
 */
class CommandRegistry
{
    /** @var array<string, Command> */
    private array $commands = [];

    /**
     * Register a command, indexed by its lowercased trigger.
     */
    public function register(Command $command): void
    {
        $this->commands[strtolower($command->trigger())] = $command;
    }

    /**
     * Match input text against registered commands.
     * Returns the matched Command, or null if no match.
     */
    public function match(string $text): ?Command
    {
        return $this->commands[strtolower(trim($text))] ?? null;
    }
}
