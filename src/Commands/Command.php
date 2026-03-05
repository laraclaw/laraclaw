<?php

namespace LaraClaw\Commands;

use LaraClaw\DTOs\IncomingMessage;
use LaraClaw\Models\Thread;

interface Command
{
    /**
     * The trigger text for this command (e.g. "!new").
     */
    public function trigger(): string;

    /**
     * Handle the command. Return the text to continue processing with, or null
     * to halt execution and skip the agent entirely.
     */
    public function handle(IncomingMessage $message, Thread $thread): ?string;
}
