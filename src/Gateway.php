<?php

namespace LaraClaw;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use LaraClaw\Agents\ChatBotAgent;
use LaraClaw\Commands\CommandRegistry;
use LaraClaw\Models\Conversation;
use LaraClaw\Models\UserAccount;
use Laravel\Ai\Contracts\ConversationStore;

/**
 * Handles all orchestration for an inbound message: account validation, conversation resolution,
 * command processing, agent construction, and prompting. Every entry point calls this.
 */
class Gateway
{
    public function __construct(
        private readonly CommandRegistry $commandRegistry,
        private readonly ConversationStore $conversations,
    ) {}

    /**
     * Validate, resolve context, and prepare the agent for an inbound message.
     * Returns null if the message should be silently dropped or a command consumed it.
     * The caller decides whether to prompt, stream, or queue the returned agent.
     */
    public function handle(Message $message): ?ChatBotAgent
    {
        if ($message->channel->intercept($message)) {
            return null;
        }

        if (blank($message->text) && $message->attachments->isEmpty()) {
            return null;
        }

        $user = $message->conversationIsDirectMessage
            ? $this->resolveDmUser($message)
            : $this->resolveGroupUser();

        if ($user === null) {
            return null;
        }

        $conversation = $this->resolveConversation($message, $user);

        // Run commands against raw text first so audio is not transcribed
        // for messages a command will consume before they ever reach the agent.
        $text = $this->processCommand($message);

        if ($text === null) {
            return null;
        }

        $agent = app(ChatBotAgent::class, [
            'message' => $message,
            'conversation' => $conversation,
        ]);

        $conversationId = $message->conversationIsDirectMessage
            ? $this->dmConversationId($message, $user)
            : $conversation->conversation_id;

        if ($conversationId) {
            $agent->continue($conversationId, as: $user);
        } else {
            $agent->forUser($user);
        }

        // If a command rewrote the text, use that. Otherwise use the agent text,
        // which appends file metadata so the agent knows where to find attachments.
        // Audio transcription is handled by TranscribeAudio middleware on the agent.
        $agent->inputText = ($text !== ($message->text ?? ''))
            ? $text
            : $message->agentText();

        $agent->inputAttachments = $message->agentAttachments();

        return $agent;
    }

    /**
     * Look up the registered user for a DM. Returns null if the account is not registered,
     * in which case the message is dropped silently.
     */
    private function resolveDmUser(Message $message): mixed
    {
        $account = UserAccount::where('channel', $message->channel->name)
            ->where('account', $message->conversationKey)
            ->with('user')
            ->first();

        return $account?->user;
    }

    /**
     * Find the configured admin user for group and open channel messages.
     * Returns null and logs a warning if no owner is configured.
     */
    private function resolveGroupUser(): mixed
    {
        $userModel = config('laraclaw.auth.user_model');
        $user = $userModel::find(config('laraclaw.auth.admin_user_id'));

        if (! $user) {
            Log::warning('LaraClaw: no owner user configured (LARACLAW_OWNER_ID)');
        }

        return $user;
    }

    /**
     * Find or create the conversation record for this message.
     * For group channels, the conversation also stores the AI conversation ID.
     */
    private function resolveConversation(Message $message, mixed $user): Conversation
    {
        if ($message->conversationIsDirectMessage) {
            return Conversation::firstOrCreate([
                'channel' => $message->channel->name,
                'key' => $message->conversationKey,
            ]);
        }

        $conversation = Conversation::where('channel', $message->channel->name)
            ->where('key', $message->conversationKey)
            ->first();

        if ($conversation) {
            return $conversation;
        }

        try {
            return Conversation::create([
                'channel' => $message->channel->name,
                'key' => $message->conversationKey,
                'conversation_id' => $this->conversations->storeConversation(
                    $user->getAuthIdentifier(),
                    $message->channel->name . ':' . $message->conversationKey,
                ),
            ]);
        } catch (UniqueConstraintViolationException) {
            return Conversation::where('channel', $message->channel->name)
                ->where('key', $message->conversationKey)
                ->firstOrFail();
        }
    }

    /**
     * Return the AI conversation ID to continue for a DM, or null to start fresh.
     * Checks if the user requested a new conversation via the cache key set by the !new command.
     */
    private function dmConversationId(Message $message, mixed $user): ?string
    {
        $cacheKey = "new_conversation:{$message->channel->name}:{$message->conversationKey}";

        if (Cache::pull($cacheKey)) {
            return null;
        }

        return $this->conversations->latestConversationId($user->getAuthIdentifier());
    }

    /**
     * Check if the message matches a registered command and run it.
     * Returns null if the command took full ownership of the message.
     * Returns the text to continue with otherwise, which the command may have rewritten.
     */
    private function processCommand(Message $message): ?string
    {
        $text = $message->text ?? '';
        $command = $this->commandRegistry->match($text);

        if (! $command) {
            return $text;
        }

        $result = $command->handle($message);

        if (! $result instanceof Message) {
            return null;
        }

        return $result->text ?? $text;
    }
}
