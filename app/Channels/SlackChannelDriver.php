<?php

namespace App\Channels;

use App\Channels\Contracts\ChannelDriver;
use App\Channels\DTOs\Attachment;
use App\Channels\DTOs\AttachmentType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SlackChannelDriver implements ChannelDriver
{
    public function __construct(
        private string $channelId,
        private string $userId,
        private ?string $messageText,
        /** @var Collection<int, Attachment> */
        private Collection $messageAttachments,
        private ?string $threadTs = null,
        private ?string $messageTs = null,
    ) {}

    public static function fromEvent(array $event): self
    {
        $attachments = collect();
        $botToken = config('laraclaw.channels.slack.bot_token');

        foreach ($event['files'] ?? [] as $file) {
            $filename = $file['name'] ?? 'unknown';
            $mimeType = $file['mimetype'] ?? 'application/octet-stream';
            $path = config('laraclaw.attachments.path') . '/' . Str::uuid() . '/' . $filename;
            $disk = config('laraclaw.attachments.disk', 'local');

            $contents = Http::withToken($botToken)
                ->get($file['url_private_download'])
                ->body();

            Storage::disk($disk)->put($path, $contents);

            $attachments->push(new Attachment(
                type: AttachmentType::fromMimeType($mimeType),
                path: $path,
                disk: $disk,
                mimeType: $mimeType,
                size: $file['size'] ?? null,
                filename: $filename,
            ));
        }

        return new self(
            channelId: $event['channel'],
            userId: $event['user'],
            messageText: $event['text'] ?? null,
            messageAttachments: $attachments,
            threadTs: $event['thread_ts'] ?? $event['ts'] ?? null,
            messageTs: $event['ts'] ?? null,
        );
    }

    public function platform(): string
    {
        return 'slack';
    }

    public function conversationId(): string
    {
        return $this->channelId;
    }

    public function senderId(): string
    {
        return $this->userId;
    }

    public function text(): ?string
    {
        return $this->messageText;
    }

    public function attachments(): Collection
    {
        return $this->messageAttachments;
    }

    public function reply(string $text): void
    {
        Http::withToken(config('laraclaw.channels.slack.bot_token'))
            ->post('https://slack.com/api/chat.postMessage', array_filter([
                'channel' => $this->channelId,
                'text' => $text,
                'thread_ts' => $this->threadTs,
            ]));
    }

    public function sendTypingIndicator(): void
    {
        if (! $this->messageTs) {
            return;
        }

        Http::withToken(config('laraclaw.channels.slack.bot_token'))
            ->post('https://slack.com/api/reactions.add', [
                'channel' => $this->channelId,
                'name' => '+1',
                'timestamp' => $this->messageTs,
            ]);
    }
}
