<?php

namespace LaraClaw\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use LaraClaw\Enums\ChannelType;
use LaraClaw\Models\UserAccount;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;

/**
 * Artisan command that links an existing user to a channel account identifier.
 */
class ChannelAddCommand extends Command
{
    protected $signature = 'laraclaw:channel-add {user} {channel} {identifier}';

    protected $description = 'Link a user to a channel identifier (telegram, slack, email)';

    /**
     * Resolve the user by ID or email, create the UserAccount record, and report the result.
     */
    public function handle(): int
    {
        $userInput = $this->argument('user');
        $channelValue = $this->argument('channel');
        $identifier = $this->argument('identifier');

        $channel = ChannelType::tryFrom($channelValue);

        if (! $channel) {
            $valid = implode(', ', array_column(ChannelType::cases(), 'value'));
            error("Invalid channel '{$channelValue}'. Valid options: {$valid}");

            return self::FAILURE;
        }

        $userModel = config('laraclaw.auth.user_model');

        $user = is_numeric($userInput)
            ? $userModel::find((int) $userInput)
            : $userModel::where('email', $userInput)->first();

        if (! $user) {
            error("User not found: {$userInput}");

            return self::FAILURE;
        }

        try {
            UserAccount::create([
                'user_id' => $user->getAuthIdentifier(),
                'channel' => $channel,
                'account' => $identifier,
            ]);
        } catch (UniqueConstraintViolationException) {
            error("Account already registered: {$channel->value}:{$identifier}");

            return self::FAILURE;
        }

        info("Linked {$channel->value}:{$identifier} → user #{$user->getAuthIdentifier()} ({$user->email})");

        return self::SUCCESS;
    }
}
