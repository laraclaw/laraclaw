<?php

namespace LaraClaw\Commands;

use LaraClaw\Channels\Channel;
use Illuminate\Contracts\Auth\Authenticatable;

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
