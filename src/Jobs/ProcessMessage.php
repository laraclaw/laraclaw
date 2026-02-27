<?php

namespace LaraClaw\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use LaraClaw\Agents\ChatBotAgent;
use LaraClaw\Calendar\Contracts\CalendarDriver;
use LaraClaw\Channels\Channel;
use LaraClaw\Channels\DTOs\AttachmentType;
use LaraClaw\Commands\CommandRegistry;
use LaraClaw\Models\ChannelConversation;
use LaraClaw\Models\UserAccount;
use LaraClaw\PendingAudioReply;
use LaraClaw\PendingFileReply;
use LaraClaw\PendingImageReply;
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
        private Channel $channel,
    ) {}

    public function failed(Throwable $exception): void
    {
        Log::error('ProcessMessage failed', [
            'channel' => $this->channel->identifier(),
            'error' => $exception->getMessage(),
        ]);

        try {
            $this->channel->send('Sorry, something went wrong processing your message. Please try again.');
        } catch (Throwable) {
            // Nothing more we can do if the channel itself is unavailable.
        }
    }

    public function handle(ConversationStore $conversations, ?CalendarDriver $calendarDriver = null): void
    {
        $this->channel->acknowledge();

        $text = $this->channel->text() ?? '';

        // Transcribe audio if no text provided
        if (blank($text)) {
            $audio = $this->channel->attachments()->first(fn ($a) => $a->type === AttachmentType::Audio);
            if ($audio) {
                $text = Transcription::fromStorage($audio->path, $audio->disk)->generate()->text;
            }
        }

        // Build attachment objects for the agent
        $agentAttachments = [];
        foreach ($this->channel->attachments() as $attachment) {
            $agentAttachments[] = match ($attachment->type) {
                AttachmentType::Image => Image::fromStorage($attachment->path, $attachment->disk),
                AttachmentType::Document => Document::fromStorage($attachment->path, $attachment->disk),
                default => null,
            };
        }
        $agentAttachments = array_filter($agentAttachments);

        // Append attachment metadata so the agent knows the disk/path for tool use
        $attachmentMeta = collect($this->channel->attachments())
            ->filter(fn ($a) => in_array($a->type, [AttachmentType::Image, AttachmentType::Document]))
            ->map(fn ($a) => ['type' => $a->type->value, 'disk' => $a->disk, 'path' => $a->path])
            ->values()
            ->all();

        if (! empty($attachmentMeta)) {
            $text .= "\n\n[Attached files: " . json_encode($attachmentMeta) . ']';
        }

        // Resolve user and conversation
        $userIdentifier = $this->channel->userIdentifier();

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
            [$channelType, $channelKey] = $this->parseIdentifier($this->channel->identifier());

            try {
                $channelConversation = ChannelConversation::where('channel', $channelType)
                    ->where('key', $channelKey)
                    ->first()
                    ?? ChannelConversation::create([
                        'channel' => $channelType,
                        'key' => $channelKey,
                        'conversation_id' => $conversations->storeConversation(
                            $user->getAuthIdentifier(),
                            $this->channel->identifier(),
                        ),
                    ]);
            } catch (UniqueConstraintViolationException) {
                $channelConversation = ChannelConversation::where('channel', $channelType)
                    ->where('key', $channelKey)
                    ->first();
            }

            $conversationId = $channelConversation->conversation_id;
        }

        // Check for commands before running the agent
        $command = app(CommandRegistry::class)->match($text);

        if ($command) {
            $response = $command->handle($this->channel, $user);

            if ($response) {
                $this->channel->send($response);
            }

            return;
        }

        $agent = new ChatBotAgent(
            channel: $this->channel,
            calendarDriver: $calendarDriver,
        );

        if ($conversationId) {
            $agent = $agent->continue($conversationId, as: $user);
        }

        $response = $conversationId
            ? $agent->prompt($text, $agentAttachments)
            : $agent->forUser($user)->prompt($text, $agentAttachments);

        $audioReply = app(PendingAudioReply::class);
        $imageReply = app(PendingImageReply::class);
        $fileReply = app(PendingFileReply::class);

        foreach ($audioReply->paths as $audioPath) {
            $this->channel->sendAudio($audioPath);
            if (file_exists($audioPath)) {
                unlink($audioPath);
            }
        }

        if ($imageReply->path && $imageReply->disk) {
            $this->channel->sendPhoto($imageReply->disk, $imageReply->path);
        }

        if (! empty($fileReply->files)) {
            $this->channel->sendFiles($fileReply->files);
        }

        $this->channel->send($response);
    }

    private function parseIdentifier(string $identifier): array
    {
        return explode(':', $identifier, 2);
    }
}
