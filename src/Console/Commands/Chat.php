<?php

namespace LaraClaw\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Authenticatable;
use LaraClaw\Channels\TerminalChannel;
use LaraClaw\Jobs\ProcessMessage;
use LaraClaw\Message;
use LaraClaw\Models\UserAccount;

use function LaraClaw\Support\markdownToAnsi;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\spin;

/**
 * Interactive REPL that pipes terminal input through ProcessMessage using the TerminalChannel.
 */
class Chat extends Command
{
    protected $signature = 'laraclaw:chat {user? : User ID or email (defaults to admin_user_id)}';

    protected $description = 'Start an interactive chat session with the AI agent in your terminal';

    public function handle(): int
    {
        $channel = new TerminalChannel;

        $user = $this->resolveUser();

        if (! $user) {
            return self::FAILURE;
        }

        // Ensure a UserAccount exists for the terminal channel so ProcessMessage
        // can resolve the owner when processing as a DM.
        UserAccount::firstOrCreate([
            'channel' => $channel->name,
            'account' => $channel->conversationKey(),
        ], [
            'user_id' => $user->getAuthIdentifier(),
        ]);

        info('Chat session started. Type your message and press Enter. Ctrl+C to exit.');

        while (true) {
            $input = readline('❯ ');

            if (blank($input)) {
                continue;
            }

            $message = new Message(
                channel: $channel,
                text: $input,
                conversationKey: $channel->conversationKey(),
                conversationIsDirectMessage: true,
            );

            spin(
                callback: fn () => ProcessMessage::dispatchSync($message),
                message: 'Fetching response...'
            );

            if ($reply = $channel->flush()) {
                note(markdownToAnsi($reply));
            }
        }

        return self::SUCCESS;
    }

    private function resolveUser(): ?Authenticatable
    {
        $userInput = $this->argument('user') ?? config('laraclaw.auth.admin_user_id');

        if (! $userInput) {
            error('No user specified and LARACLAW_ADMIN_USER_ID is not set.');

            return null;
        }

        $userModel = config('laraclaw.auth.user_model');

        $user = is_numeric($userInput)
            ? $userModel::find((int) $userInput)
            : $userModel::where('email', $userInput)->first();

        if (! $user) {
            error("User not found: {$userInput}");

            return null;
        }

        return $user;
    }
}
