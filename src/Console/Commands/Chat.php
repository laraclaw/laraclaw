<?php

namespace LaraClaw\Console\Commands;

use Illuminate\Console\Command;
use LaraClaw\Channels\TerminalChannel;
use LaraClaw\Jobs\ProcessMessage;
use LaraClaw\Message;
use LaraClaw\Models\UserAccount;

/**
 * Interactive REPL that pipes terminal input through ProcessMessage using the TerminalChannel.
 */
class Chat extends Command
{
    protected $signature = 'laraclaw:chat {user? : User ID or email (defaults to admin_user_id)}';

    protected $description = 'Start an interactive chat session with the AI agent in your terminal';

    public function handle(): int
    {
        $channel = new TerminalChannel($this);

        $userInput = $this->argument('user') ?? config('laraclaw.auth.admin_user_id');

        if (! $userInput) {
            $this->error('No user specified and LARACLAW_ADMIN_USER_ID is not set.');

            return self::FAILURE;
        }

        $userModel = config('laraclaw.auth.user_model');
        $user = is_numeric($userInput)
            ? $userModel::find((int) $userInput)
            : $userModel::where('email', $userInput)->first();

        if (! $user) {
            $this->error("User not found: {$userInput}");

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

        $this->line('Chat session started. Type your message and press Enter. Ctrl+C to exit.');
        $this->newLine();

        while (true) {
            $text = $this->ask('<fg=cyan>You</>');

            if ($text === null || $text === '') {
                continue;
            }

            $message = new Message(
                channel: $channel,
                text: $text,
                conversationKey: $channel->conversationKey(),
                conversationIsDirectMessage: true,
            );

            ProcessMessage::dispatchSync($message);

            $this->newLine();
        }

        return self::SUCCESS;
    }
}
