<?php

namespace LaraClaw\Channels;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LaraClaw\Channels\Concerns\ChecksRedisForConfirmations;
use LaraClaw\Channels\Contracts\SupportsAcknowledgement;
use LaraClaw\Channels\Contracts\SupportsConfirmation;
use LaraClaw\DTOs\Attachment;
use LaraClaw\Message;
use League\CommonMark\CommonMarkConverter;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatAction;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;
use SergiX44\Nutgram\Telegram\Types\Media\PhotoSize;
use SergiX44\Nutgram\Telegram\Types\Message\Message as NutgramMessage;
use Throwable;

class TelegramChannel extends Channel implements SupportsAcknowledgement, SupportsConfirmation
{
    use ChecksRedisForConfirmations;

    public readonly string $name = 'telegram';

    /**
     * Create a new TelegramChannel instance.
     */
    public function __construct(
        private int|string $chatId,
        private Nutgram $bot,
    ) {}

    /**
     * Build a Message from a raw Nutgram message, downloading any
     * attachments to storage.
     */
    public static function parseIncomingMessage(NutgramMessage $raw, Nutgram $bot): Message
    {
        $channel = new self(chatId: $raw->chat->id, bot: $bot);

        return new Message(
            channel: $channel,
            conversationKey: $channel->conversationKey(),
            conversationIsDirectMessage: $channel->conversationIsDirectMessage(),
            text: $raw->text ?? $raw->caption ?? null,
            attachments: $channel->collectAttachments($raw),
        );
    }

    /**
     * Send a typing indicator to show the bot is processing.
     */
    public function acknowledge(): void
    {
        try {
            $this->bot->sendChatAction(ChatAction::TYPING, chat_id: $this->chatId);
        } catch (Throwable $e) {
            Log::warning('Telegram typing indicator failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Convert Markdown to Telegram HTML and send.
     */
    public function send(string $message): void
    {
        $html = (new CommonMarkConverter)->convert($message)->getContent();
        $html = preg_replace('/<li>/', '<li>• ', $html);
        $html = strip_tags($html, '<b><strong><i><em><u><s><a><code><pre><blockquote>');
        $html = trim($html);

        $this->bot->sendMessage($html, chat_id: $this->chatId, parse_mode: ParseMode::HTML);
    }

    /**
     * Send each attachment using the appropriate Telegram method based on type.
     */
    public function handleAttachments(Collection $attachments): void
    {
        foreach ($attachments as $attachment) {
            if ($attachment->isAudio()) {
                $this->withTempFile($attachment, function (string $tempPath) {
                    $this->bot->sendVoice(
                        voice: InputFile::make(fopen($tempPath, 'r')),
                        chat_id: $this->chatId,
                    );
                });
            } elseif ($attachment->isImage()) {
                $this->withTempFile($attachment, function (string $tempPath) use ($attachment) {
                    $this->bot->sendPhoto(
                        photo: InputFile::make(fopen($tempPath, 'r'), basename($attachment->path)),
                        chat_id: $this->chatId,
                    );
                });
            } else {
                $this->withTempFile($attachment, function (string $tempPath) use ($attachment) {
                    $this->bot->sendDocument(
                        document: InputFile::make(fopen($tempPath, 'r'), $attachment->filename ?? basename($attachment->path)),
                        chat_id: $this->chatId,
                    );
                });
            }
        }
    }

    /**
     * Use the Telegram chat ID as the conversation key.
     */
    private function conversationKey(): string
    {
        return (string) $this->chatId;
    }

    /**
     * Download all media from the incoming message to storage.
     */
    private function collectAttachments(NutgramMessage $raw): Collection
    {
        $disk = config('laraclaw.filesystem.attachments_disk', 'local');
        $basePath = config('laraclaw.filesystem.attachments_path', 'attachments').'/telegram';

        $files = [];

        if (! empty($raw->photo)) {
            $photo = collect($raw->photo)->sortByDesc(fn (PhotoSize $p) => $p->file_size ?? 0)->first();
            $files[] = $this->downloadFile($photo->file_id, 'image/jpeg', null, $disk, $basePath);
        }

        if ($raw->audio) {
            $files[] = $this->downloadFile($raw->audio->file_id, $raw->audio->mime_type ?? 'audio/mpeg', $raw->audio->file_name, $disk, $basePath);
        }

        if ($raw->voice) {
            $files[] = $this->downloadFile($raw->voice->file_id, $raw->voice->mime_type ?? 'audio/ogg', null, $disk, $basePath);
        }

        if ($raw->video) {
            $files[] = $this->downloadFile($raw->video->file_id, $raw->video->mime_type ?? 'video/mp4', $raw->video->file_name, $disk, $basePath);
        }

        if ($raw->document) {
            $files[] = $this->downloadFile($raw->document->file_id, $raw->document->mime_type ?? 'application/octet-stream', $raw->document->file_name, $disk, $basePath);
        }

        return collect($files)->filter()->values();
    }

    /**
     * Download a single Telegram file to storage and return its DTO.
     */
    private function downloadFile(string $fileId, string $mimeType, ?string $fileName, string $disk, string $basePath): ?Attachment
    {
        $file = $this->bot->getFile($fileId);

        if (! $file) {
            return null;
        }

        $fileName ??= basename($file->file_path ?? $fileId);
        $path = $basePath.'/'.Str::uuid().'/'.$fileName;

        $tempPath = sys_get_temp_dir().'/'.Str::uuid();

        try {
            $file->save($tempPath);
            Storage::disk($disk)->put($path, file_get_contents($tempPath));
        } finally {
            if (file_exists($tempPath)) {
                unlink($tempPath);
            }
        }

        return new Attachment(path: $path, disk: $disk, mimeType: $mimeType, filename: $fileName);
    }

    /**
     * Positive chat IDs are private chats; negative are groups.
     */
    private function conversationIsDirectMessage(): bool
    {
        return $this->chatId > 0;
    }

    /**
     * Nutgram requires a local file stream, so read from storage into a temp
     * file, invoke the callback, then clean up regardless of outcome.
     */
    private function withTempFile(Attachment $attachment, callable $callback): void
    {
        $tempPath = sys_get_temp_dir().'/'.basename($attachment->path);

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
