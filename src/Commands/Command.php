<?php

namespace LaraClaw\Commands;

use LaraClaw\Message;

interface Command
{
    /**
     * The trigger text for this command (e.g. "!new").
     */
    public function trigger(): string;

    /**
     * Handle the command. Return a Message to continue to the agent (optionally with
     * mutated text), or null to halt execution after the command completes.
     */
    public function handle(Message $message): ?Message;
}
