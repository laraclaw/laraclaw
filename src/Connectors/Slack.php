<?php

namespace Laraclaw\Connectors;

use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laraclaw\DTOs\Attachment;
use Laraclaw\DTOs\IncomingMessage;
use Laraclaw\Enums\ConnectorType;
use Laraclaw\Models\Account;
use Laraclaw\Models\Thread;
use Laraclaw\Services\Attachments;
use Laraclaw\Services\AttachmentSizeGuard;
use RuntimeException;
use Throwable;

use function Laraclaw\Support\markdownToMrkdwn;

class Slack extends Connector
{
    public ?string $channelId = null;

    public ?string $threadTs = null;

    public ConnectorType $type {
        get {
            return ConnectorType::Slack;
        }
    }

    /**
     * Validate the incoming Slack event request. Throws if the event should not be processed.
     */
    public static function validateEvent(Request $request): void
    {
        $botUserId = config('laraclaw.connectors.slack.bot_user_id');
        $isEnabled = config('laraclaw.connectors.slack.enabled');

        $isDirectMessage = Str::startsWith($request->input('event.channel', ''), 'D');

        $validator = Validator::make($request->all(), [
            'type' => ['required', Rule::in(['event_callback'])],
            'event.type' => ['required', Rule::in(['message'])],
            'event.bot_id' => ['prohibited'],
            'event.subtype' => ['nullable', Rule::in(['file_share'])],
            'event.channel' => ['required'],
            'event.user' => [Rule::requiredIf($isDirectMessage)],
        ], [
            'type.required' => 'NOT_CALLBACK_EVENT',
            'type.in' => 'NOT_CALLBACK_EVENT',
            'event.type.required' => 'NOT_MESSAGE',
            'event.type.in' => 'NOT_MESSAGE',
            'event.bot_id.prohibited' => 'BOT_MESSAGE',
            'event.subtype.in' => 'UNSUPPORTED_SUBTYPE',
            'event.channel.required' => 'NO_CHANNEL',
            'event.user.required' => 'NO_USER',
        ])->after(function ($validator) use ($request, $isEnabled, $botUserId, $isDirectMessage): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if (! $isEnabled || ! $botUserId) {
                $validator->errors()->add('event', 'CHANNEL_DISABLED');

                return;
            }

            $isMentioned = str_contains((string) $request->input('event.text', ''), "<@{$botUserId}>");
            $isThreadReply = self::isReplyInKnownThread($request);

            if (! $isDirectMessage && ! $isMentioned && ! $isThreadReply) {
                $validator->errors()->add('event', 'BOT_NOT_MENTIONED');

                return;
            }

            if ($isDirectMessage && ! Account::query()->forConnector($request->input('event.user'), ConnectorType::Slack)->exists()) {
                $validator->errors()->add('event', 'UNREGISTERED_ACCOUNT');

                return;
            }

            if (blank($request->input('event.text')) && empty($request->input('event.files'))) {
                $validator->errors()->add('event', 'EMPTY_MESSAGE_WITHOUT_ATTACHMENTS');
            }
        });

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }
    }

    /**
     * Build an IncomingMessage from a raw Slack event payload,
     * downloading any file attachments to storage.
     */
    public static function createIncomingMessageFrom(array $event, Attachments $attachments): IncomingMessage
    {
        $uuid = (string) Str::uuid();
        $isDirectMessage = str_starts_with((string) $event['channel'], 'D');

        return new IncomingMessage(
            text: $event['text'] ?? null,
            connector: ConnectorType::Slack,
            key: self::getThreadKey($event),
            isDirectMessage: $isDirectMessage,
            attachments: self::saveAttachments($event, $attachments->inbound($uuid)),
            uuid: $uuid,
        );
    }

    /**
     * Build a Slack connector instance with channelId and threadTs resolved from a thread key.
     */
    public static function forKey(string $key): self
    {
        $connector = new self;
        $connector->applyKey($key);

        return $connector;
    }

    /**
     * Bare keys (no colon) are user IDs for DMs. Keys with a colon are
     * channel:threadTs pairs from public channels.
     */
    public static function isDirectMessage(string $key): bool
    {
        return ! str_contains($key, ':');
    }

    /**
     * Check if the event is a reply inside a thread the bot has already responded to.
     * This lets thread replies through without requiring an explicit mention.
     */
    private static function isReplyInKnownThread(Request $request): bool
    {
        $threadTs = $request->input('event.thread_ts');
        $channel = $request->input('event.channel');

        if (! $threadTs || ! $channel) {
            return false;
        }

        return Thread::where('connector', ConnectorType::Slack)
            ->where('key', "{$channel}:{$threadTs}")
            ->exists();
    }

    /**
     * Returns a thread key: `user` in DMs and `channel:ts` in channels.
     */
    private static function getThreadKey(array $event): string
    {
        return str_starts_with((string) $event['channel'], 'D')
            ? $event['user']
            : implode(':', [
                $event['channel'],
                $event['thread_ts'] ?? $event['ts'],
            ]);
    }

    /**
     * Download all file attachments from the event to storage.
     */
    private static function saveAttachments(array $event, Attachments $attachments): array
    {
        return collect($event['files'] ?? [])
            ->map(fn (array $file): ?Attachment => self::downloadFile($file, $attachments))
            ->filter()
            ->toArray();
    }

    /**
     * Download a single Slack file to storage and return its DTO.
     */
    private static function downloadFile(array $file, Attachments $attachments): ?Attachment
    {
        $url = $file['url_private_download'] ?? $file['url_private'] ?? null;

        if (! $url) {
            return null;
        }

        $mimeType = $file['mimetype'] ?? 'application/octet-stream';
        $fileName = $file['name'] ?? 'attachment';

        try {
            $response = Http::withToken(self::token())->withOptions(['stream' => true])->get($url);

            if (! $response->successful()) {
                Log::warning('Slack file download failed', ['url' => $url, 'status' => $response->status()]);

                return null;
            }

            $body = AttachmentSizeGuard::body($response, ['connector' => 'slack', 'url' => $url]);

            if ($body === null) {
                return null;
            }

            $disk = config('laraclaw.filesystem.attachments_disk', 'local');
            $path = $attachments->set($fileName, $body);

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
        return config('laraclaw.connectors.slack.bot_token');
    }

    /**
     * React with a thumbsup to acknowledge the incoming message.
     */
    public function thumbsUp(array $event): void
    {
        try {
            Http::withToken(self::token())
                ->post('https://slack.com/api/reactions.add', [
                    'channel' => $event['channel'],
                    'name' => 'thumbsup',
                    'timestamp' => $event['ts'],
                ]);
        } catch (Throwable $e) {
            Log::warning('Slack reaction failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Resolve the destination from the thread key, open a DM if needed,
     * upload any reply attachments, then post the message.
     */
    public function reply(?Thread $thread, string $text, ?Collection $attachments = null): void
    {
        if ($thread instanceof Thread) {
            $this->applyKey($thread->key);
        }

        if (str_starts_with((string) $this->channelId, 'U')) {
            $response = Http::withToken(self::token())
                ->post('https://slack.com/api/conversations.open', ['users' => $this->channelId]);

            $channelId = $response->json('channel.id');

            if (! $response->successful() || ! $channelId) {
                throw new RuntimeException("Failed to open Slack DM with user {$this->channelId}: " . $response->body());
            }

            $this->channelId = $channelId;
        }

        $isDm = str_starts_with((string) $this->channelId, 'D');

        if ($attachments?->isNotEmpty()) {
            $this->handleAttachments($attachments);
        }

        $payload = [
            'channel' => $this->channelId,
            'text' => $this->toMrkdwn($text),
        ];

        if (! $isDm && $this->threadTs) {
            $payload['thread_ts'] = $this->threadTs;
        }

        $response = Http::withToken(self::token())
            ->post('https://slack.com/api/chat.postMessage', $payload);

        if (! $isDm && ! $this->threadTs && $response->successful()) {
            $data = $response->json();
            if ($data['ok'] && isset($data['ts'])) {
                $this->threadTs = $data['ts'];
            }
        }
    }

    /**
     * Parse a thread key into channelId and threadTs on this instance.
     * Keys with ':' are channel:threadTs pairs; bare keys are user IDs for DMs.
     */
    private function applyKey(string $key): void
    {
        if (str_contains($key, ':')) {
            [$this->channelId, $this->threadTs] = explode(':', $key, 2);
        } else {
            $this->channelId = $key;
        }
    }

    /**
     * Upload each attachment to Slack via the external upload API.
     */
    private function handleAttachments(Collection $attachments): void
    {
        foreach ($attachments as $attachment) {
            $this->uploadAttachment($attachment);
        }
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
     * Convert Markdown to Slack mrkdwn format.
     */
    private function toMrkdwn(string $text): string
    {
        return markdownToMrkdwn($text);
    }

    /**
     * Upload a file to Slack via the external upload API.
     * First fetch a signed upload URL, then complete the upload to post it.
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

        $uploadResponse = Http::attach('file', file_get_contents($filePath), $fileName)->post($uploadUrl);

        if (! $uploadResponse->successful()) {
            Log::warning('Slack file upload failed', ['status' => $uploadResponse->status(), 'file' => $fileName]);

            return false;
        }

        $completePayload = [
            'files' => [['id' => $fileId, 'title' => $fileName]],
            'channel_id' => $this->channelId,
        ];

        if ($this->threadTs) {
            $completePayload['thread_ts'] = $this->threadTs;
        }

        $completeResponse = Http::withToken(self::token())
            ->post('https://slack.com/api/files.completeUploadExternal', $completePayload);

        if (! $completeResponse->successful() || ! $completeResponse->json('ok')) {
            Log::warning('Slack file complete upload failed', ['response' => $completeResponse->body(), 'file' => $fileName]);

            return false;
        }

        return true;
    }
}
