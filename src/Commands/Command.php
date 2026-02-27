<?php

namespace LaraClaw\Commands;

use Illuminate\Contracts\Auth\Authenticatable;
use LaraClaw\Channels\Channel;

interface Command
{
    /**
     * The command prefix (e.g. "!new").
     */
    public function prefix(): string;

    /**
     * Handle the command. Return a response string to send to the user,
     * or null to silently complete.
     */
    public function handle(Channel $channel, Authenticatable $user): ?string;
}
