<?php

namespace LaraClaw\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use LaraClaw\Agents\ChatBotAgent;
use LaraClaw\Calendar\Contracts\CalendarDriver;
use LaraClaw\Channels\Contracts\SupportsAcknowledgement;
use LaraClaw\Channels\Contracts\SupportsStreaming;
use LaraClaw\Commands\CommandRegistry;
use LaraClaw\DTOs\Attachment;
use LaraClaw\Message;
use LaraClaw\Models\Conversation;
use LaraClaw\Models\UserAccount;
use LaraClaw\SkillRegistry;
use LaraClaw\Tools\ToolRegistry;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Streaming\Events\TextDelta;
use Laravel\Ai\Transcription;
use Throwable;

/**
 * Takes a message off the queue, passes it through the AI agent and sends the response.
 */
class ProcessMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(
        private Message $message,
    ) {}

    /**
     * Load the sender and their conversation, prompt the agent and deliver its response.
     */
    public function handle(
        ConversationStore $conversations,
        CommandRegistry $commandRegistry,
        SkillRegistry $skillRegistry,
        ToolRegistry $toolRegistry,
        ?CalendarDriver $calendarDriver = null,
    ): void {
        /** @var Collection<int, Attachment> $replyAttachments */
        $replyAttachments = collect();

        $channel = $this->message->channel;

        if ($channel instanceof SupportsAcknowledgement) {
            $channel->acknowledge();
        }

        $text = $this->resolveText();
        $agentAttachments = $this->resolveAgentAttachments();

        $context = $this->resolveContext($conversations);

        if ($context === null) {
            return;
        }

        ['user' => $user, 'conversation' => $conversation, 'conversationId' => $conversationId] = $context;

        $text = $this->processCommand($text, $commandRegistry);

        if ($text === null) {
            return;
        }

        $agent = new ChatBotAgent(
            message: $this->message,
            skillRegistry: $skillRegistry,
            replyAttachments: $replyAttachments,
            toolRegistry: $toolRegistry,
            conversation: $conversation,
            calendarDriver: $calendarDriver,
        );

        if ($conversationId) {
            $agent = $agent->continue($conversationId, as: $user);
        }

        $stream = $conversationId
            ? $agent->stream($text, $agentAttachments)
            : $agent->forUser($user)->stream($text, $agentAttachments);

        $stream->each(function (object $event) use ($channel): void {
            if ($channel instanceof SupportsStreaming && $event instanceof TextDelta) {
                $channel->chunk($event->delta);
            }
        });

        $channel->handleAttachments($replyAttachments);
        $channel->send($stream->text ?? '');
    }

    /**
     * Log what went wrong and let the user know something failed via their channel.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('ProcessMessage failed', [
            'channel' => $this->message->channel->name,
            'key' => $this->message->conversationKey,
            'error' => $exception->getMessage(),
        ]);

        $this->message->channel->send('Sorry, something went wrong processing your message. Please try again.');
    }

    /**
     * Get the message text, transcribing any audio attachment if no text was provided.
     * Then append file metadata at the end so the agent knows where to find the attachments.
     */
    private function resolveText(): string
    {
        $text = $this->message->text ?? '';

        if (blank($text)) {
            $audio = $this->message->attachments->first(fn ($a) => $a->isAudio());
            if ($audio) {
                $text = Transcription::fromStorage($audio->path, $audio->disk)->generate()->text;
            }
        }

        $attachmentMeta = $this->message->attachments
            ->filter(fn ($a) => $a->isImage() || $a->isDocument())
            ->map(fn ($a) => ['type' => $a->mimeType, 'disk' => $a->disk, 'path' => $a->path])
            ->values()
            ->all();

        if (! empty($attachmentMeta)) {
            $text .= PHP_EOL . PHP_EOL . '[Attached files: ' . json_encode($attachmentMeta) . ']';
        }

        return $text;
    }

    /**
     * Build the list of images and documents the agent will receive alongside the message text.
     *
     * @return array<int, Image|Document>
     */
    private function resolveAgentAttachments(): array
    {
        return $this->message->attachments
            ->map(fn (Attachment $a) => match (true) {
                $a->isImage() => Image::fromStorage($a->path, $a->disk),
                $a->isDocument() => Document::fromStorage($a->path, $a->disk),
                default => null,
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Look up the sender and find or create their conversation record.
     *
     * Returns null if this is a group message and no owner user is configured,
     * in which case the job exits quietly.
     *
     * @return array{user: mixed, conversation: Conversation, conversationId: string|null}|null
     */
    private function resolveContext(ConversationStore $conversations): ?array
    {
        $channel = $this->message->channel;

        if ($this->message->conversationIsDirectMessage) {
            $userAccount = UserAccount::where('channel', $channel->name)
                ->where('account', $this->message->conversationKey)
                ->with('user')
                ->firstOrFail();

            $user = $userAccount->user;

            $conversation = Conversation::firstOrCreate([
                'channel' => $channel->name,
                'key' => $this->message->conversationKey,
            ]);

            $startFresh = Cache::pull("new_conversation:{$channel->name}:{$this->message->conversationKey}");
            $conversationId = $startFresh ? null : $conversations->latestConversationId($user->getAuthIdentifier());

            return compact('user', 'conversation', 'conversationId');
        }

        // Group channels always run as the owner user with one conversation per channel.
        $userModel = config('laraclaw.auth.user_model');
        $user = $userModel::find(config('laraclaw.auth.admin_user_id'));

        if (! $user) {
            Log::warning('LaraClaw: no owner user configured (LARACLAW_OWNER_ID)');

            return null;
        }

        // The !new command is scoped to individual users so it cannot reset a shared group conversation.
        $conversation = Conversation::where('channel', $channel->name)
            ->where('key', $this->message->conversationKey)
            ->first();

        if (! $conversation) {
            try {
                $conversation = Conversation::create([
                    'channel' => $channel->name,
                    'key' => $this->message->conversationKey,
                    'conversation_id' => $conversations->storeConversation(
                        $user->getAuthIdentifier(),
                        $channel->name . ':' . $this->message->conversationKey,
                    ),
                ]);
            } catch (UniqueConstraintViolationException) {
                $conversation = Conversation::where('channel', $channel->name)
                    ->where('key', $this->message->conversationKey)
                    ->firstOrFail();
            }
        }

        $conversationId = $conversation->conversation_id;

        return compact('user', 'conversation', 'conversationId');
    }

    /**
     * Check if the message matches a registered command and run it.
     *
     * Returns null if the command took full ownership of the message and nothing more should happen.
     * Otherwise returns the text to continue with, which the command may have rewritten.
     */
    private function processCommand(string $text, CommandRegistry $commandRegistry): ?string
    {
        $command = $commandRegistry->match($text);

        if (! $command) {
            return $text;
        }

        $result = $command->handle($this->message);

        if ($result === null) {
            return null;
        }

        return $result->text ?? $text;
    }
}
