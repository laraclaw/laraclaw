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
use Laravel\Ai\Transcription;
use Throwable;

/**
 * Core queued job that processes an inbound message through the AI agent and sends the reply.
 */
class ProcessMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(
        private Message $message,
    ) {}

    /**
     * Log the error and attempt to notify the user via the channel when the job fails.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('ProcessMessage failed', [
            'channel' => $this->message->channel->name,
            'key' => $this->message->conversationKey,
            'error' => $exception->getMessage(),
        ]);

        try {
            $this->message->channel->send('Sorry, something went wrong processing your message. Please try again.');
        } catch (Throwable) {
            // Nothing more we can do if the channel itself is unavailable.
        }
    }

    /**
     * Resolve the user, conversation, and agent, then prompt the AI and deliver the reply.
     */
    public function handle(ConversationStore $conversations, CommandRegistry $commandRegistry, SkillRegistry $skillRegistry, ToolRegistry $toolRegistry, ?CalendarDriver $calendarDriver = null): void
    {
        /** @var Collection<int, Attachment> $replyAttachments */
        $replyAttachments = collect();

        $channel = $this->message->channel;

        if ($channel instanceof SupportsAcknowledgement) {
            $channel->acknowledge();
        }

        $text = $this->message->text ?? '';

        // Transcribe audio if no text provided
        if (blank($text)) {
            $audio = $this->message->attachments->first(fn ($a) => $a->isAudio());
            if ($audio) {
                $text = Transcription::fromStorage($audio->path, $audio->disk)->generate()->text;
            }
        }

        // Build attachment objects for the agent
        $agentAttachments = [];
        foreach ($this->message->attachments as $attachment) {
            $agentAttachments[] = match (true) {
                $attachment->isImage() => Image::fromStorage($attachment->path, $attachment->disk),
                $attachment->isDocument() => Document::fromStorage($attachment->path, $attachment->disk),
                default => null,
            };
        }
        $agentAttachments = array_filter($agentAttachments);

        // Append attachment metadata so the agent knows the disk/path for tool use
        $attachmentMeta = $this->message->attachments
            ->filter(fn ($a) => $a->isImage() || $a->isDocument())
            ->map(fn ($a) => ['type' => $a->mimeType, 'disk' => $a->disk, 'path' => $a->path])
            ->values()
            ->all();

        if (! empty($attachmentMeta)) {
            $text .= "\n\n[Attached files: " . json_encode($attachmentMeta) . ']';
        }

        // Resolve user and conversation
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
        } else {
            // Group/open channel: use the owner user, keyed conversation per channel
            $userModel = config('laraclaw.auth.user_model');
            $user = $userModel::find(config('laraclaw.auth.admin_user_id'));

            if (! $user) {
                Log::warning('LaraClaw: no owner user configured (LARACLAW_OWNER_ID)');

                return;
            }

            // Group conversations are never reset via !new — that's per-user and doesn't
            // apply to a shared channel conversation.
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
        }

        // Check for commands before running the agent
        $command = $commandRegistry->match($text);

        if ($command) {
            $result = $command->handle($this->message);

            if ($result === null) {
                return;
            }

            $text = $result->text ?? $text;
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

        $response = $conversationId
            ? $agent->prompt($text, $agentAttachments)
            : $agent->forUser($user)->prompt($text, $agentAttachments);

        $channel->handleAttachments($replyAttachments);
        $channel->send($response);
    }
}
