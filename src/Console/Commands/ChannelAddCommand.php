<?php

namespace LaraClaw\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use LaraClaw\Models\UserAccount;

class ChannelAddCommand extends Command
{
    protected $signature = 'laraclaw:channel-add {user} {channel} {identifier}';

    protected $description = 'Link a user to a channel identifier (telegram, slack, email)';

    public function handle(): int
    {
        $userInput = $this->argument('user');
        $channel = $this->argument('channel');
        $identifier = $this->argument('identifier');

        $userModel = config('laraclaw.auth.user_model');

        $user = is_numeric($userInput)
            ? $userModel::find((int) $userInput)
            : $userModel::where('email', $userInput)->first();

        if (! $user) {
            $this->error("User not found: {$userInput}");

            return self::FAILURE;
        }

        try {
            UserAccount::create([
                'user_id' => $user->getAuthIdentifier(),
                'channel' => $channel,
                'account' => $identifier,
            ]);
        } catch (UniqueConstraintViolationException) {
            $this->error("Account already registered: {$channel}:{$identifier}");

            return self::FAILURE;
        }

        $this->info("Linked {$channel}:{$identifier} → user #{$user->getAuthIdentifier()} ({$user->email})");

        return self::SUCCESS;
    }
}
