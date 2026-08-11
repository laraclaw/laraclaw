<?php

namespace Laraclaw\Connectors;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laraclaw\DTOs\Attachment;
use Laraclaw\DTOs\IncomingMessage;
use Laraclaw\Enums\ConnectorType;
use Laraclaw\Models\Account;
use Laraclaw\Models\Thread;
use Laraclaw\Services\Attachments;
use League\CommonMark\CommonMarkConverter;
use Telegram\Bot\Actions;
use Telegram\Bot\Api;
use Telegram\Bot\FileUpload\InputFile;
use Telegram\Bot\Objects\Message as TelegramMessage;
use Telegram\Bot\Objects\PhotoSize;
use Throwable;

class Telegram extends Connector
{
    public ConnectorType $type {
        get {
            return ConnectorType::Telegram;
        }
    }

    /**
     * Bind the target chat ID and the configured Telegram bot client.
     */
    public function __construct(
        private readonly int|string $chatId,
        private readonly Api $bot,
    ) {}

    /**
     * Validate an incoming Telegram message. Throws if the message should not be processed.
     */
    public static function validateEvent(TelegramMessage $message): void
    {
        if (! config('laraclaw.connectors.telegram.enabled')) {
            throw ValidationException::withMessages(['telegram' => 'CHANNEL_DISABLED']);
        }

        $chatId = $message->getChat()->getId();
        $isDirectMessage = $chatId > 0;

        if ($isDirectMessage && ! Account::query()->forConnector((string) $chatId, ConnectorType::Telegram)->exists()) {
            throw ValidationException::withMessages(['telegram' => 'UNREGISTERED_ACCOUNT']);
        }

        if (blank($message->getText()) && blank($message->getCaption()) && self::hasNoMedia($message)) {
            throw ValidationException::withMessages(['telegram' => 'EMPTY_MESSAGE']);
        }
    }

    /**
     * Build an IncomingMessage DTO from a raw Telegram message,
     * downloading any file attachments to storage.
     */
    public static function createIncomingMessageFrom(TelegramMessage $message, Api $bot, Attachments $attachments): IncomingMessage
    {
        $uuid = (string) Str::uuid();
        $chatId = $message->getChat()->getId();

        return new IncomingMessage(
            text: $message->getText() ?? $message->getCaption() ?? null,
            connector: ConnectorType::Telegram,
            key: (string) $chatId,
            isDirectMessage: $chatId > 0,
            attachments: self::saveAttachments($message, $bot, $attachments->inbound($uuid)),
            uuid: $uuid,
        );
    }

    /**
     * Positive chat IDs are DMs, negative are groups.
     */
    public static function isDirectMessage(string $key): bool
    {
        return (int) $key > 0;
    }

    /**
     * Return true if the message has no photo, audio, voice, video, or document.
     */
    private static function hasNoMedia(TelegramMessage $message): bool
    {
        $photo = $message->getPhoto();

        return ($photo === null || $photo === [])
            && ! $message->getAudio()
            && ! $message->getVoice()
            && ! $message->getVideo()
            && ! $message->getDocument();
    }

    /**
     * Download all media from the incoming message to storage.
     *
     * @return Attachment[]
     */
    private static function saveAttachments(TelegramMessage $message, Api $bot, Attachments $attachments): array
    {
        $files = [];
        $photo = $message->getPhoto();

        if ($photo !== null && $photo !== []) {
            $largest = collect($photo)->sortByDesc(fn (PhotoSize $p): int => $p->getFileSize() ?? 0)->first();
            $files[] = self::downloadFile($largest->getFileId(), 'image/jpeg', null, $bot, $attachments);
        }

        $audio = $message->getAudio();
        if ($audio) {
            $files[] = self::downloadFile($audio->getFileId(), $audio->getMimeType() ?? 'audio/mpeg', $audio->getFileName(), $bot, $attachments);
        }

        $voice = $message->getVoice();
        if ($voice) {
            $files[] = self::downloadFile($voice->getFileId(), $voice->getMimeType() ?? 'audio/ogg', null, $bot, $attachments);
        }

        $video = $message->getVideo();
        if ($video) {
            $files[] = self::downloadFile($video->getFileId(), $video->getMimeType() ?? 'video/mp4', $video->getFileName(), $bot, $attachments);
        }

        $document = $message->getDocument();
        if ($document) {
            $files[] = self::downloadFile($document->getFileId(), $document->getMimeType() ?? 'application/octet-stream', $document->getFileName(), $bot, $attachments);
        }

        return collect($files)->filter()->toArray();
    }

    /**
     * Download a single Telegram file to storage and return its DTO.
     */
    private static function downloadFile(string $fileId, string $mimeType, ?string $fileName, Api $bot, Attachments $attachments): ?Attachment
    {
        $file = $bot->getFile(['file_id' => $fileId]);

        if (! $file) {
            return null;
        }

        $filePath = $file->getFilePath();

        if (! $filePath) {
            return null;
        }

        $fileName ??= basename($filePath);
        $disk = config('laraclaw.filesystem.attachments_disk', 'local');
        $downloadUrl = "https://api.telegram.org/file/bot{$bot->getAccessToken()}/{$filePath}";

        try {
            $response = Http::get($downloadUrl);

            if (! $response->successful()) {
                Log::warning('Telegram file download failed', ['fileId' => $fileId, 'status' => $response->status()]);

                return null;
            }

            $path = $attachments->set($fileName, $response->body());

            return new Attachment(path: $path, disk: $disk, mimeType: $mimeType, filename: $fileName);
        } catch (Throwable $e) {
            Log::warning('Telegram file download error', ['fileId' => $fileId, 'error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * Send a typing indicator to show the bot is processing.
     */
    public function showTypingIndicator(): void
    {
        try {
            $this->bot->sendChatAction([
                'chat_id' => $this->chatId,
                'action' => Actions::TYPING,
            ]);
        } catch (Throwable $e) {
            Log::warning('Telegram typing indicator failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Send a reply to the given thread, optionally with file attachments.
     */
    public function reply(?Thread $thread, string $text, ?Collection $attachments = null): void
    {
        if ($attachments?->isNotEmpty()) {
            $this->handleAttachments($attachments);
        }

        $this->send($text);
    }

    /**
     * Send each attachment using the appropriate Telegram method based on type.
     */
    private function handleAttachments(Collection $attachments): void
    {
        foreach ($attachments as $attachment) {
            if ($attachment->isAudio()) {
                $this->withTempFile($attachment, function (string $tempPath): void {
                    $this->bot->sendVoice([
                        'chat_id' => $this->chatId,
                        'voice' => new InputFile($tempPath),
                    ]);
                });
            } elseif ($attachment->isImage()) {
                $this->withTempFile($attachment, function (string $tempPath): void {
                    $this->bot->sendPhoto([
                        'chat_id' => $this->chatId,
                        'photo' => new InputFile($tempPath),
                    ]);
                });
            } else {
                $this->withTempFile($attachment, function (string $tempPath): void {
                    $this->bot->sendDocument([
                        'chat_id' => $this->chatId,
                        'document' => new InputFile($tempPath),
                    ]);
                });
            }
        }
    }

    /**
     * Convert Markdown to Telegram HTML and send.
     */
    private function send(string $message): void
    {
        $html = (new CommonMarkConverter)->convert($message)->getContent();
        $html = preg_replace('/<li>/', '<li>• ', $html);
        $html = strip_tags((string) $html, '<b><strong><i><em><u><s><a><code><pre><blockquote>');
        $html = trim($html);

        $this->bot->sendMessage([
            'chat_id' => $this->chatId,
            'text' => $html,
            'parse_mode' => 'HTML',
        ]);
    }

    /**
     * Read the file from storage into a temp file, invoke the callback, then clean up.
     */
    private function withTempFile(Attachment $attachment, callable $callback): void
    {
        $tempPath = sys_get_temp_dir() . '/' . basename($attachment->path);

        file_put_contents($tempPath, Storage::disk($attachment->disk)->get($attachment->path));

        try {
            $callback($tempPath);
        } finally {
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
        }
    }
}
