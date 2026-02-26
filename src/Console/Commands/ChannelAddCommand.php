<?php

namespace LaraClaw\Console\Commands;

use LaraClaw\Models\UserChannel;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;

class ChannelAddCommand extends Command
{
    protected $signature = 'laraclaw:channel-add {user} {channel} {identifier}';

    protected $description = 'Link a user to a channel identifier (telegram, slack, email)';

    public function handle(): int
    {
        $userInput = $this->argument('user');
        $channel = $this->argument('channel');
        $identifier = $this->argument('identifier');

        $userModel = config('laraclaw.user_model');

        $user = is_numeric($userInput)
            ? $userModel::find((int) $userInput)
            : $userModel::where('email', $userInput)->first();

        if (! $user) {
            $this->error("User not found: {$userInput}");
            return self::FAILURE;
        }

        $fullIdentifier = "{$channel}:{$identifier}";

        try {
            UserChannel::create([
                'user_id' => $user->getAuthIdentifier(),
                'identifier' => $fullIdentifier,
            ]);
        } catch (UniqueConstraintViolationException) {
            $this->error("Identifier already registered: {$fullIdentifier}");
            return self::FAILURE;
        }

        $this->info("Linked {$fullIdentifier} → user #{$user->getAuthIdentifier()} ({$user->email})");

        return self::SUCCESS;
    }
}
