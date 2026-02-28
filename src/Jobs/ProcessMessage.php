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
use LaraClaw\Channels\Contracts\SupportsAudio;
use LaraClaw\Channels\Contracts\SupportsFiles;
use LaraClaw\Channels\Contracts\SupportsImages;
use LaraClaw\Commands\CommandRegistry;
use LaraClaw\DTOs\Attachment;
use LaraClaw\Message;
use LaraClaw\Models\Conversation;
use LaraClaw\Models\UserAccount;
use LaraClaw\SkillRegistry;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Transcription;
use Throwable;

class ProcessMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(
        private Message $message,
    ) {}

    public function failed(Throwable $exception): void
    {
        Log::error('ProcessMessage failed', [
            'channel' => $this->message->identifier(),
            'error' => $exception->getMessage(),
        ]);

        try {
            $this->message->channel->send('Sorry, something went wrong processing your message. Please try again.');
        } catch (Throwable) {
            // Nothing more we can do if the channel itself is unavailable.
        }
    }

    public function handle(ConversationStore $conversations, SkillRegistry $skillRegistry, ?CalendarDriver $calendarDriver = null): void
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
        $userIdentifier = $channel->userIdentifier();

        if ($userIdentifier !== null) {
            // DM: must be the registered owner
            [$channelType, $accountKey] = $this->parseIdentifier($userIdentifier);

            $userAccount = UserAccount::where('channel', $channelType)
                ->where('account', $accountKey)
                ->with('user')
                ->first();

            if (! $userAccount) {
                Log::info('LaraClaw: message from unregistered account ignored', [
                    'channel' => $channelType,
                    'account' => $accountKey,
                ]);

                return;
            }

            $user = $userAccount->user;

            $conversation = Conversation::firstOrCreate([
                'channel' => $channelType,
                'key' => $accountKey,
            ]);

            $startFresh = Cache::pull("new_conversation:{$user->getAuthIdentifier()}");
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
            [$channelType, $channelKey] = $this->parseIdentifier($channel->identifier());

            try {
                $conversation = Conversation::where('channel', $channelType)
                    ->where('key', $channelKey)
                    ->first()
                    ?? Conversation::create([
                        'channel' => $channelType,
                        'key' => $channelKey,
                        'conversation_id' => $conversations->storeConversation(
                            $user->getAuthIdentifier(),
                            $channel->identifier(),
                        ),
                    ]);
            } catch (UniqueConstraintViolationException) {
                $conversation = Conversation::where('channel', $channelType)
                    ->where('key', $channelKey)
                    ->first();
            }

            $conversationId = $conversation->conversation_id;
        }

        // Check for commands before running the agent
        $command = app(CommandRegistry::class)->match($text);

        if ($command) {
            $response = $command->handle($channel, $user);

            if ($response) {
                $channel->send($response);
            }

            return;
        }

        $agent = new ChatBotAgent(
            channel: $channel,
            skillRegistry: $skillRegistry,
            replyAttachments: $replyAttachments,
            conversation: $conversation,
            calendarDriver: $calendarDriver,
        );

        if ($conversationId) {
            $agent = $agent->continue($conversationId, as: $user);
        }

        $response = $conversationId
            ? $agent->prompt($text, $agentAttachments)
            : $agent->forUser($user)->prompt($text, $agentAttachments);

        foreach ($replyAttachments as $attachment) {
            if ($attachment->isAudio() && $channel instanceof SupportsAudio) {
                $channel->sendAudio($attachment);
            } elseif ($attachment->isImage() && $channel instanceof SupportsImages) {
                $channel->sendImage($attachment);
            } elseif ($channel instanceof SupportsFiles) {
                $channel->sendFile($attachment);
            }
        }

        $channel->send($response);
    }

    private function parseIdentifier(string $identifier): array
    {
        return explode(':', $identifier, 2);
    }
}
