<?php

namespace LaraClaw\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use LaraClaw\Enums\ConnectorType;
use LaraClaw\Models\UserAccount;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;

/**
 * Artisan command that links an existing user to a connector account identifier.
 */
class ConnectorAddCommand extends Command
{
    protected $signature = 'laraclaw:connector-add {user} {connector} {identifier}';

    protected $description = 'Link a user to a connector identifier (telegram, slack, email)';

    /**
     * Resolve the user by ID or email, create the UserAccount record, and report the result.
     */
    public function handle(): int
    {
        $userInput = $this->argument('user');
        $connectorValue = $this->argument('connector');
        $identifier = $this->argument('identifier');

        $connector = ConnectorType::tryFrom($connectorValue);

        if (! $connector) {
            $valid = implode(', ', array_column(ConnectorType::cases(), 'value'));
            error("Invalid connector '{$connectorValue}'. Valid options: {$valid}");

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
                'connector' => $connector,
                'account' => $identifier,
            ]);
        } catch (UniqueConstraintViolationException) {
            error("Account already registered: {$connector->value}:{$identifier}");

            return self::FAILURE;
        }

        info("Linked {$connector->value}:{$identifier} → user #{$user->getAuthIdentifier()} ({$user->email})");

        return self::SUCCESS;
    }
}
