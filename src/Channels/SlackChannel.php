<?php

namespace LaraClaw\Channels;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LaraClaw\Channels\Concerns\ChecksRedisForConfirmations;
use LaraClaw\Channels\Contracts\SupportsAcknowledgement;
use LaraClaw\Channels\Contracts\SupportsConfirmation;
use LaraClaw\DTOs\Attachment;
use LaraClaw\Message;
use RuntimeException;
use Throwable;

class SlackChannel extends Channel implements SupportsAcknowledgement, SupportsConfirmation
{
    use ChecksRedisForConfirmations;

    public readonly string $name = 'slack';

    /**
     * Create a new SlackChannel instance.
     */
    public function __construct(
        private string $channelId,
        private ?string $threadTs = null,
        private ?string $messageTs = null,
        private ?string $userId = null,
    ) {}

    /**
     * Open or retrieve a DM channel with the given Slack user ID.
     */
    public static function openDm(string $userId): self
    {
        $response = Http::withToken(self::token())
            ->post('https://slack.com/api/conversations.open', ['users' => $userId]);

        $channelId = $response->json('channel.id');

        if (! $response->successful() || ! $channelId) {
            throw new RuntimeException("Failed to open Slack DM with user {$userId}: ".$response->body());
        }

        return new self(channelId: $channelId);
    }

    /**
     * Build a Message from a raw Slack event payload, downloading any
     * file attachments to storage.
     */
    public static function parseIncomingMessage(array $event): Message
    {
        $channel = new self(
            channelId: $event['channel'],
            threadTs: $event['thread_ts'] ?? $event['ts'] ?? null,
            messageTs: $event['ts'] ?? null,
            userId: $event['user'] ?? null,
        );

        return new Message(
            channel: $channel,
            conversationKey: $channel->conversationKey(),
            conversationIsDirectMessage: $channel->conversationIsDirectMessage(),
            text: $event['text'] ?? null,
            attachments: self::collectAttachments($event),
        );
    }

    /**
     * Download all file attachments from the event payload to storage.
     */
    private static function collectAttachments(array $event): Collection
    {
        $disk = config('laraclaw.filesystem.attachments_disk', 'local');
        $basePath = config('laraclaw.filesystem.attachments_path', 'attachments').'/slack';

        return collect($event['files'] ?? [])
            ->map(fn (array $file) => self::downloadFile($file, $disk, $basePath))
            ->filter()
            ->values();
    }

    /**
     * Download a single Slack file to storage and return its DTO.
     */
    private static function downloadFile(array $file, string $disk, string $basePath): ?Attachment
    {
        $url = $file['url_private_download'] ?? $file['url_private'] ?? null;

        if (! $url) {
            return null;
        }

        $mimeType = $file['mimetype'] ?? 'application/octet-stream';
        $fileName = $file['name'] ?? 'attachment';
        $path = $basePath.'/'.Str::uuid().'/'.$fileName;

        try {
            $response = Http::withToken(self::token())->get($url);

            if (! $response->successful()) {
                Log::warning('Slack file download failed', ['url' => $url, 'status' => $response->status()]);

                return null;
            }

            Storage::disk($disk)->put($path, $response->body());

            return new Attachment(path: $path, disk: $disk, mimeType: $mimeType, filename: $fileName);
        } catch (Throwable $e) {
            Log::warning('Slack file download error', ['url' => $url, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Retrieve the configured Slack bot token.
     */
    private static function token(): string
    {
        return config('laraclaw.channels.slack.bot_token');
    }

    /**
     * React with a thumbsup to acknowledge the incoming message.
     */
    public function acknowledge(): void
    {
        if (! $this->messageTs) {
            return;
        }

        try {
            Http::withToken(self::token())
                ->post('https://slack.com/api/reactions.add', [
                    'channel' => $this->channelId,
                    'name' => 'thumbsup',
                    'timestamp' => $this->messageTs,
                ]);
        } catch (Throwable $e) {
            Log::warning('Slack reaction failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Convert Markdown to Slack mrkdwn and post to the channel or thread.
     * Captures the thread_ts on the first reply so subsequent messages thread correctly.
     */
    public function send(string $message): void
    {
        $payload = [
            'channel' => $this->channelId,
            'text' => $this->toMrkdwn($message),
        ];

        if (! $this->conversationIsDirectMessage() && $this->threadTs) {
            $payload['thread_ts'] = $this->threadTs;
        }

        $response = Http::withToken(self::token())
            ->post('https://slack.com/api/chat.postMessage', $payload);

        if (! $this->conversationIsDirectMessage() && ! $this->threadTs && $response->successful()) {
            $data = $response->json();
            if ($data['ok'] && isset($data['ts'])) {
                $this->threadTs = $data['ts'];
            }
        }
    }

    /**
     * Upload each attachment to Slack via the external upload API.
     */
    public function handleAttachments(Collection $attachments): void
    {
        foreach ($attachments as $attachment) {
            $this->uploadAttachment($attachment);
        }
    }

    /**
     * Key DM conversations by user ID and threaded conversations by channel and thread timestamp.
     */
    private function conversationKey(): string
    {
        return $this->conversationIsDirectMessage()
            ? $this->userId ?? throw new RuntimeException('Slack DM event is missing user ID.')
            : "{$this->channelId}:{$this->threadTs}";
    }

    /**
     * Upload a single attachment DTO to Slack.
     */
    private function uploadAttachment(Attachment $attachment): bool
    {
        return $this->uploadFile(
            Storage::disk($attachment->disk)->path($attachment->path),
            $attachment->filename ?? basename($attachment->path),
        );
    }

    /**
     * Slack DM channel IDs start with 'D'.
     */
    private function conversationIsDirectMessage(): bool
    {
        return str_starts_with($this->channelId, 'D');
    }

    /**
     * Convert Markdown to Slack mrkdwn format.
     */
    private function toMrkdwn(string $text): string
    {
        // Bold: **text** or __text__ → *text*
        $text = preg_replace('/\*\*(.+?)\*\*/s', '*$1*', $text);
        $text = preg_replace('/__(.+?)__/s', '*$1*', $text);

        // Italic: _text_ → _text_ (already correct), but *text* (single) → _text_
        // Only convert single * that aren't already bold (bold was already processed)
        $text = preg_replace('/(?<!\*)\*(?!\*)(.+?)(?<!\*)\*(?!\*)/s', '_$1_', $text);

        // Strikethrough: ~~text~~ → ~text~
        $text = preg_replace('/~~(.+?)~~/s', '~$1~', $text);

        // Links: [text](url) → <url|text>
        $text = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<$2|$1>', $text);

        // Headings: ## Heading → *Heading*
        $text = preg_replace('/^#{1,6}\s+(.+)$/m', '*$1*', $text);

        return $text;
    }

    /**
     * Upload a file to Slack using the two-step external upload API:
     * first get a signed upload URL, then complete the upload to post it.
     */
    private function uploadFile(string $filePath, string $fileName): bool
    {
        $fileSize = filesize($filePath);

        $urlResponse = Http::withToken(self::token())
            ->get('https://slack.com/api/files.getUploadURLExternal', [
                'filename' => $fileName,
                'length' => $fileSize,
            ]);

        if (! $urlResponse->successful() || ! $urlResponse->json('ok')) {
            Log::warning('Slack file upload URL failed', ['response' => $urlResponse->body()]);

            return false;
        }

        $uploadUrl = $urlResponse->json('upload_url');
        $fileId = $urlResponse->json('file_id');

        Http::attach('file', file_get_contents($filePath), $fileName)->post($uploadUrl);

        $completePayload = [
            'files' => [['id' => $fileId, 'title' => $fileName]],
            'channel_id' => $this->channelId,
        ];

        if ($this->threadTs) {
            $completePayload['thread_ts'] = $this->threadTs;
        }

        Http::withToken(self::token())
            ->post('https://slack.com/api/files.completeUploadExternal', $completePayload);

        return true;
    }
}
