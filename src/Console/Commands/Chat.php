<?php

namespace LaraClaw\Console\Commands;

use Illuminate\Console\Command;
use LaraClaw\Channels\TerminalChannel;
use LaraClaw\Jobs\ProcessMessage;
use LaraClaw\Message;
use LaraClaw\Models\UserAccount;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\text;

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
            error('No user specified and LARACLAW_ADMIN_USER_ID is not set.');

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
            $input = text(label: '', required: true);

            $message = new Message(
                channel: $channel,
                text: $input,
                conversationKey: $channel->conversationKey(),
                conversationIsDirectMessage: true,
            );

            ProcessMessage::dispatchSync($message);

            echo PHP_EOL;
        }

        return self::SUCCESS;
    }
}
