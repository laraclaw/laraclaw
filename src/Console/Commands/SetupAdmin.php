<?php

namespace Laraclaw\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Laraclaw\Console\Concerns\ConfiguresEnv;

use function Laravel\Prompts\info;
use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Provision or update the Laraclaw owner account.
 */
class SetupAdmin extends Command
{
    use ConfiguresEnv;

    protected $signature = 'laraclaw:setup-admin';

    protected $description = 'Set up the Laraclaw admin user account';

    public function handle(): int
    {
        $this->heading('⭐ Admin Account');

        info("First, let's set up your admin user account.");

        $userModel = config('laraclaw.auth.user_model');
        $existingId = $this->readEnv('LARACLAW_ADMIN_USER_ID');
        $existingAdmin = $userModel::find($existingId);

        if ($existingAdmin && select("Admin user already exists ({$existingAdmin->email}). Keep it?", [
            'existing' => 'Yes, keep the existing admin user',
            'new' => 'No, create a new one',
        ]) === 'existing') {
            return self::SUCCESS;
        }

        $user = $this->createUser($userModel);
        $this->writeEnv('LARACLAW_ADMIN_USER_ID', $user->id);

        return self::SUCCESS;
    }

    private function createUser(string $userModel): mixed
    {
        $name = text("👤 What's your name?", placeholder: 'E.g. Alex', required: true);

        info("Nice to meet you, {$name}!");

        $email = text(
            label: "✉️ What's your email?",
            placeholder: 'E.g. john@example.com',
            required: true,
            validate: fn (string $value): ?string => match (true) {
                ! filter_var($value, FILTER_VALIDATE_EMAIL) => 'Please enter a valid email address.',
                $userModel::where('email', $value)->exists() => 'That email is already taken.',
                default => null,
            },
        );

        info("Great. Now, let's create your password.");

        $password = password('🔑 Password', required: true);

        password(
            label: '🔑 Repeat password',
            required: true,
            validate: fn (string $value): ?string => $value !== $password ? 'Passwords do not match.' : null,
        );

        return $userModel::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($password),
        ]);
    }
}
