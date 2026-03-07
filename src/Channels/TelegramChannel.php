<?php

namespace LaraClaw\Channels;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LaraClaw\Channels\Concerns\ChecksRedisForConfirmations;
use LaraClaw\Channels\Contracts\SupportsConfirmation;
use LaraClaw\DTOs\Attachment;
use LaraClaw\DTOs\IncomingMessage;
use LaraClaw\Enums\ChannelType;
use LaraClaw\Models\Thread;
use LaraClaw\Models\UserAccount;
use LaraClaw\Services\Attachments;
use League\CommonMark\CommonMarkConverter;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatAction;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;
use SergiX44\Nutgram\Telegram\Types\Media\PhotoSize;
use SergiX44\Nutgram\Telegram\Types\Message\Message as NutgramMessage;
use Throwable;

class TelegramChannel extends Channel implements SupportsConfirmation
{
    use ChecksRedisForConfirmations;

    public ChannelType $type { get { return ChannelType::Telegram; } }

    public function __construct(
        private int|string $chatId,
        private Nutgram $bot,
    ) {}

    /**
     * Validate an incoming Telegram message. Throws if the message should not be processed.
     */
    public static function validateEvent(NutgramMessage $message): void
    {
        if (! config('laraclaw.channels.telegram.enabled')) {
            throw ValidationException::withMessages(['telegram' => 'CHANNEL_DISABLED']);
        }

        $chatId = $message->chat->id;
        $isDirectMessage = $chatId > 0;

        if ($isDirectMessage && ! UserAccount::query()->forChannel((string) $chatId, ChannelType::Telegram)->exists()) {
            throw ValidationException::withMessages(['telegram' => 'UNREGISTERED_ACCOUNT']);
        }

        if (blank($message->text) && blank($message->caption) && self::hasNoMedia($message)) {
            throw ValidationException::withMessages(['telegram' => 'EMPTY_MESSAGE']);
        }
    }

    /**
     * Build an IncomingMessage DTO from a raw Nutgram message,
     * downloading any file attachments to storage.
     */
    public static function createIncomingMessageFrom(NutgramMessage $message, Nutgram $bot, Attachments $attachments): IncomingMessage
    {
        $uuid = (string) Str::uuid();
        $chatId = $message->chat->id;

        return new IncomingMessage(
            text: $message->text ?? $message->caption ?? null,
            channel: ChannelType::Telegram,
            key: (string) $chatId,
            isDirectMessage: $chatId > 0,
            attachments: self::saveAttachments($message, $bot, $attachments->inbound($uuid)),
            uuid: $uuid,
        );
    }

    /**
     * Send a typing indicator to show the bot is processing.
     */
    public function showTypingIndicator(): void
    {
        try {
            $this->bot->sendChatAction(ChatAction::TYPING, chat_id: $this->chatId);
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
     * Return true if the message has no photo, audio, voice, video, or document.
     */
    private static function hasNoMedia(NutgramMessage $message): bool
    {
        return ($message->photo === null || $message->photo === [])
            && ! $message->audio
            && ! $message->voice
            && ! $message->video
            && ! $message->document;
    }

    /**
     * Download all media from the incoming message to storage.
     *
     * @return Attachment[]
     */
    private static function saveAttachments(NutgramMessage $message, Nutgram $bot, Attachments $attachments): array
    {
        $files = [];

        if ($message->photo !== null && $message->photo !== []) {
            $photo = collect($message->photo)->sortByDesc(fn (PhotoSize $p): int => $p->file_size ?? 0)->first();
            $files[] = self::downloadFile($photo->file_id, 'image/jpeg', null, $bot, $attachments);
        }

        if ($message->audio) {
            $files[] = self::downloadFile($message->audio->file_id, $message->audio->mime_type ?? 'audio/mpeg', $message->audio->file_name, $bot, $attachments);
        }

        if ($message->voice) {
            $files[] = self::downloadFile($message->voice->file_id, $message->voice->mime_type ?? 'audio/ogg', null, $bot, $attachments);
        }

        if ($message->video) {
            $files[] = self::downloadFile($message->video->file_id, $message->video->mime_type ?? 'video/mp4', $message->video->file_name, $bot, $attachments);
        }

        if ($message->document) {
            $files[] = self::downloadFile($message->document->file_id, $message->document->mime_type ?? 'application/octet-stream', $message->document->file_name, $bot, $attachments);
        }

        return collect($files)->filter()->toArray();
    }

    /**
     * Download a single Telegram file to storage and return its DTO.
     */
    private static function downloadFile(string $fileId, string $mimeType, ?string $fileName, Nutgram $bot, Attachments $attachments): ?Attachment
    {
        $file = $bot->getFile($fileId);

        if (! $file) {
            return null;
        }

        $fileName ??= basename($file->file_path ?? $fileId);
        $disk = config('laraclaw.filesystem.attachments_disk', 'local');

        $tempPath = sys_get_temp_dir() . '/' . Str::uuid();

        try {
            $file->save($tempPath);
            $path = $attachments->set($fileName, file_get_contents($tempPath));

            return new Attachment(path: $path, disk: $disk, mimeType: $mimeType, filename: $fileName);
        } catch (Throwable $e) {
            Log::warning('Telegram file download error', ['fileId' => $fileId, 'error' => $e->getMessage()]);

            return null;
        } finally {
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
        }
    }

    /**
     * Send each attachment using the appropriate Telegram method based on type.
     */
    private function handleAttachments(Collection $attachments): void
    {
        foreach ($attachments as $attachment) {
            if ($attachment->isAudio()) {
                $this->withTempFile($attachment, function (string $tempPath): void {
                    $this->bot->sendVoice(
                        voice: InputFile::make(fopen($tempPath, 'r')),
                        chat_id: $this->chatId,
                    );
                });
            } elseif ($attachment->isImage()) {
                $this->withTempFile($attachment, function (string $tempPath) use ($attachment): void {
                    $this->bot->sendPhoto(
                        photo: InputFile::make(fopen($tempPath, 'r'), basename((string) $attachment->path)),
                        chat_id: $this->chatId,
                    );
                });
            } else {
                $this->withTempFile($attachment, function (string $tempPath) use ($attachment): void {
                    $this->bot->sendDocument(
                        document: InputFile::make(fopen($tempPath, 'r'), $attachment->filename ?? basename((string) $attachment->path)),
                        chat_id: $this->chatId,
                    );
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

        $this->bot->sendMessage($html, chat_id: $this->chatId, parse_mode: ParseMode::HTML);
    }

    /**
     * Nutgram requires a local file stream, so read from storage into a temp
     * file, invoke the callback, then clean up regardless of outcome.
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
