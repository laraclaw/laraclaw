<?php

namespace LaraClaw\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Authenticatable;
use LaraClaw\Agents\ChatBotAgent;
use LaraClaw\Calendar\Contracts\CalendarDriver;
use LaraClaw\Channels\TerminalChannel;
use LaraClaw\Commands\CommandRegistry;
use LaraClaw\Message;
use LaraClaw\Models\UserAccount;
use LaraClaw\SkillRegistry;
use LaraClaw\Tools\ToolRegistry;
use Laravel\Ai\Contracts\ConversationStore;

use function LaraClaw\Support\markdownToAnsi;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\spin;

/**
 * Interactive REPL that runs the AI agent directly in the terminal, without going through the queue.
 */
class Chat extends Command
{
    protected $signature = 'laraclaw:chat {user? : User ID or email (defaults to admin_user_id)}';

    protected $description = 'Start an interactive chat session with the AI agent in your terminal';

    public function handle(
        ConversationStore $conversations,
        CommandRegistry $commandRegistry,
        SkillRegistry $skillRegistry,
        ToolRegistry $toolRegistry,
        ?CalendarDriver $calendarDriver = null,
    ): int {
        $channel = new TerminalChannel;

        $user = $this->resolveUser();

        if (! $user) {
            return self::FAILURE;
        }

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

            $agent = new ChatBotAgent(
                message: $message,
                conversations: $conversations,
                commandRegistry: $commandRegistry,
                skillRegistry: $skillRegistry,
                toolRegistry: $toolRegistry,
                calendarDriver: $calendarDriver,
            );

            if (! $agent->isReady()) {
                continue;
            }

            $response = spin(
                callback: fn () => $agent->send(),
                message: 'Fetching response...',
            );

            $channel->handleAttachments($agent->replyAttachments);

            if (filled($response)) {
                note(markdownToAnsi($response));
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
