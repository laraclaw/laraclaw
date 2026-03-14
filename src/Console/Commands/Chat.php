<?php

namespace LaraClaw\Console\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Authenticatable;
use LaraClaw\Agents\ChatBotAgent;
use LaraClaw\Connectors\Terminal;
use LaraClaw\Models\Account;
use LaraClaw\Models\Thread;

use function Laravel\Prompts\info;

/**
 * Interactive REPL that runs the AI agent directly in the terminal, without going through the queue.
 */
class Chat extends Command
{
    protected $signature = 'laraclaw:chat {userId?}';

    protected $description = 'Start an interactive chat session with the AI agent in your terminal';

    /**
     * Register the terminal account if needed, then start the interactive loop.
     */
    public function handle(): int
    {
        // Resolve the user and register a terminal account if needed
        $user = $this->resolveUser();
        $connector = new Terminal;

        Account::firstOrCreate(
            ['connector' => $connector->type, 'account' => $user->getAuthIdentifier()],
            ['user_id' => $user->getAuthIdentifier()],
        );

        info('Chat session started. Type your message and press Enter. Ctrl+C to exit.');

        while (true) {
            $input = readline('❯ ');

            if (blank($input)) {
                continue;
            }

            // Build the incoming message and find or create the thread
            $incomingMessage = Terminal::createIncomingMessageFrom(input: $input, user: $user);
            $thread = Thread::forMessage($incomingMessage);

            // Prompt the agent synchronously and deliver the reply
            $agent = resolve(ChatBotAgent::class, [
                'message' => $incomingMessage,
                'thread' => $thread,
            ]);

            $response = $agent->prompt(...$incomingMessage->toAgentInput());

            $thread->update(['conversation_id' => $response->conversationId]);

            $connector->reply(thread: $thread, text: $response->text);
        }
    }

    /**
     * Find the user by ID, falling back to the configured admin user.
     */
    private function resolveUser(): Authenticatable
    {
        $userId = $this->argument('userId') ?? config('laraclaw.auth.admin_user_id');

        if (! $userId) {
            throw new Exception('No user specified and no default admin user set.');
        }

        $userModel = config('laraclaw.auth.user_model');

        return $userModel::findOrFail((int) $userId);
    }
}
