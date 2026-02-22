<?php

namespace LaraClaw\Channels;

use LaraClaw\Channels\DTOs\Attachment;
use LaraClaw\Channels\DTOs\AttachmentType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SlackChannel extends Channel
{
    public function __construct(
        private string $channelId,
        private ?string $threadTs = null,
        private ?string $messageTs = null,
        ?string $text = null,
        ?Collection $attachments = null,
    ) {
        $this->messageText = $text;
        $this->messageAttachments = $attachments ?? collect();
    }

    public static function fromEvent(array $event): self
    {
        $botToken = config('laraclaw.slack.bot_token');
        $attachments = collect();

        foreach ($event['files'] ?? [] as $file) {
            $url = $file['url_private_download'] ?? $file['url_private'] ?? null;
            if (! $url) {
                continue;
            }

            $mimeType = $file['mimetype'] ?? 'application/octet-stream';
            $fileName = $file['name'] ?? 'attachment';
            $disk = config('laraclaw.attachments.disk', 'local');
            $path = config('laraclaw.attachments.path', 'attachments').'/slack/'.Str::uuid().'/'.$fileName;

            try {
                $response = Http::withToken($botToken)->get($url);

                if (! $response->successful()) {
                    Log::warning('Slack file download failed', ['url' => $url, 'status' => $response->status()]);
                    continue;
                }

                Storage::disk($disk)->put($path, $response->body());

                $attachments->push(new Attachment(
                    type: AttachmentType::fromMimeType($mimeType),
                    path: $path,
                    disk: $disk,
                    mimeType: $mimeType,
                    filename: $fileName,
                ));
            } catch (\Throwable $e) {
                Log::warning('Slack file download error', ['url' => $url, 'error' => $e->getMessage()]);
            }
        }

        return new self(
            channelId: $event['channel'],
            threadTs: $event['thread_ts'] ?? $event['ts'] ?? null,
            messageTs: $event['ts'] ?? null,
            text: $event['text'] ?? null,
            attachments: $attachments,
        );
    }

    public function identifier(): string
    {
        return "slack:{$this->channelId}:{$this->threadTs}";
    }

    public function acknowledge(): void
    {
        if (! $this->messageTs) {
            return;
        }

        try {
            Http::withToken(config('laraclaw.slack.bot_token'))
                ->post('https://slack.com/api/reactions.add', [
                    'channel' => $this->channelId,
                    'name' => 'thumbsup',
                    'timestamp' => $this->messageTs,
                ]);
        } catch (\Throwable $e) {
            Log::warning('Slack reaction failed', ['error' => $e->getMessage()]);
        }
    }

    public function send(string $message): void
    {
        $payload = [
            'channel' => $this->channelId,
            'text' => $this->toMrkdwn($message),
        ];

        if ($this->threadTs) {
            $payload['thread_ts'] = $this->threadTs;
        }

        $response = Http::withToken(config('laraclaw.slack.bot_token'))
            ->post('https://slack.com/api/chat.postMessage', $payload);

        // If this is the first reply, capture the thread_ts for subsequent messages
        if (! $this->threadTs && $response->successful()) {
            $data = $response->json();
            if ($data['ok'] && isset($data['ts'])) {
                $this->threadTs = $data['ts'];
            }
        }
    }

    public function sendAudio(string $filePath, ?string $caption = null): void
    {
        if (! $this->uploadFile($filePath, basename($filePath), $caption)) {
            $this->send($caption ?? 'Audio reply generated.');
        }
    }

    public function sendPhoto(string $disk, string $path): void
    {
        $filePath = Storage::disk($disk)->path($path);
        $this->uploadFile($filePath, basename($path));
    }

    public function sendFiles(array $files): void
    {
        foreach ($files as $file) {
            $filePath = Storage::disk($file['disk'])->path($file['path']);
            $this->uploadFile($filePath, basename($file['path']));
        }
    }

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

    private function uploadFile(string $filePath, string $fileName, ?string $title = null): bool
    {
        $token = config('laraclaw.slack.bot_token');
        $fileSize = filesize($filePath);

        $urlResponse = Http::withToken($token)
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

        Http::attach('file', file_get_contents($filePath), $fileName)
            ->post($uploadUrl);

        $completePayload = [
            'files' => [['id' => $fileId, 'title' => $title ?? $fileName]],
            'channel_id' => $this->channelId,
        ];

        if ($this->threadTs) {
            $completePayload['thread_ts'] = $this->threadTs;
        }

        Http::withToken($token)
            ->post('https://slack.com/api/files.completeUploadExternal', $completePayload);

        return true;
    }
}
