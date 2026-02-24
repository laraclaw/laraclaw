<?php

namespace LaraClaw\Jobs;

use LaraClaw\Agents\ChatBotAgent;
use LaraClaw\Commands\CommandRegistry;
use LaraClaw\PendingAudioReply;
use LaraClaw\PendingFileReply;
use LaraClaw\PendingImageReply;
use LaraClaw\Calendar\Contracts\CalendarDriver;
use LaraClaw\Channels\Channel;
use LaraClaw\Channels\DTOs\AttachmentType;
use LaraClaw\Models\UserChannel;
use LaraClaw\Tables;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Transcription;

class ProcessMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $tries = 1;

    public function __construct(
        private Channel $channel,
    ) {}

    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessMessage failed', [
            'channel' => $this->channel->identifier(),
            'error' => $exception->getMessage(),
        ]);

        try {
            $this->channel->send('Sorry, something went wrong processing your message. Please try again.');
        } catch (\Throwable) {
            // Nothing more we can do if the channel itself is unavailable.
        }
    }

    public function handle(): void
    {
        $this->channel->acknowledge();

        try {
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
                $text .= "\n\n[Attached files: ".json_encode($attachmentMeta).']';
            }

            // Resolve user for this conversation
            if ($this->channel->autoCreatesUser()) {
                $id = $this->channel->identifier();
                $email = str_replace(':', '-', $id).'@bot.local';
                $userModel = config('laraclaw.user_model');
                $user = $userModel::firstOrCreate(
                    ['email' => $email],
                    ['name' => $id, 'password' => bcrypt(Str::random())],
                );
            } else {
                $userIdentifier = $this->channel->userIdentifier();
                $userChannel = $userIdentifier
                    ? UserChannel::where('identifier', $userIdentifier)->with('user')->first()
                    : null;

                if (! $userChannel) {
                    Log::info('LaraClaw: message from unregistered channel ignored', [
                        'identifier' => $userIdentifier,
                    ]);
                    return;
                }

                $user = $userChannel->user;
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

            // Look up existing conversation (skip if reset via !new)
            $startFresh = Cache::pull("new_conversation:{$user->getAuthIdentifier()}");

            $conversation = $startFresh ? null : DB::table(Tables::CONVERSATIONS)
                ->where('user_id', $user->getAuthIdentifier())
                ->orderByDesc('updated_at')
                ->first(['id']);

            $conversationId = $conversation?->id;

            $agent = new ChatBotAgent(
                channel: $this->channel,
                calendarDriver: app()->bound(CalendarDriver::class) ? app(CalendarDriver::class) : null,
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

            if ($audioReply->path) {
                $this->channel->sendAudio($audioReply->path);
                if (file_exists($audioReply->path)) {
                    unlink($audioReply->path);
                }
            }

            if ($imageReply->path && $imageReply->disk) {
                $this->channel->sendPhoto($imageReply->disk, $imageReply->path);
            }

            if (! empty($fileReply->files)) {
                $this->channel->sendFiles($fileReply->files);
            }

            $this->channel->send($response);
        } finally {
            // Attachments are kept in storage so the agent can reference them in follow-up messages.
            // Add a scheduled command to prune old attachment directories periodically.
        }
    }
}
