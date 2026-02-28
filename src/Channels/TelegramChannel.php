<?php

namespace LaraClaw\Channels;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LaraClaw\Channels\Concerns\ConfirmsViaRedis;
use LaraClaw\Channels\Contracts\SupportsAcknowledgement;
use LaraClaw\Channels\Contracts\SupportsAudio;
use LaraClaw\Channels\Contracts\SupportsConfirmation;
use LaraClaw\Channels\Contracts\SupportsImages;
use LaraClaw\DTOs\Attachment;
use League\CommonMark\CommonMarkConverter;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatAction;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;
use SergiX44\Nutgram\Telegram\Types\Media\PhotoSize;
use SergiX44\Nutgram\Telegram\Types\Message\Message as NutgramMessage;
use Throwable;

class TelegramChannel extends Channel implements SupportsAcknowledgement, SupportsAudio, SupportsConfirmation, SupportsImages
{
    use ConfirmsViaRedis;

    public function __construct(
        private int|string $chatId,
    ) {}

    public static function from(NutgramMessage $raw, Nutgram $bot): \LaraClaw\Message
    {
        $attachments = collect();
        $disk = config('laraclaw.filesystem.attachments_disk', 'local');
        $basePath = config('laraclaw.filesystem.attachments_path', 'attachments') . '/telegram';

        // Photo (array of PhotoSize, pick largest)
        if (! empty($raw->photo)) {
            $photo = collect($raw->photo)->sortByDesc(fn (PhotoSize $p) => $p->file_size ?? 0)->first();
            self::downloadFile($bot, $photo->file_id, 'image/jpeg', null, $disk, $basePath, $attachments);
        }

        if ($raw->audio) {
            self::downloadFile($bot, $raw->audio->file_id, $raw->audio->mime_type ?? 'audio/mpeg', $raw->audio->file_name, $disk, $basePath, $attachments);
        }

        if ($raw->voice) {
            self::downloadFile($bot, $raw->voice->file_id, $raw->voice->mime_type ?? 'audio/ogg', null, $disk, $basePath, $attachments);
        }

        if ($raw->video) {
            self::downloadFile($bot, $raw->video->file_id, $raw->video->mime_type ?? 'video/mp4', $raw->video->file_name, $disk, $basePath, $attachments);
        }

        if ($raw->document) {
            self::downloadFile($bot, $raw->document->file_id, $raw->document->mime_type ?? 'application/octet-stream', $raw->document->file_name, $disk, $basePath, $attachments);
        }

        return new \LaraClaw\Message(
            channel: new self(chatId: $raw->chat->id),
            text: $raw->text ?? $raw->caption ?? null,
            attachments: $attachments,
        );
    }

    private static function downloadFile(Nutgram $bot, string $fileId, string $mimeType, ?string $fileName, string $disk, string $basePath, Collection $attachments): void
    {
        $file = $bot->getFile($fileId);

        if (! $file) {
            return;
        }

        $fileName ??= basename($file->file_path ?? $fileId);
        $path = $basePath . '/' . Str::uuid() . '/' . $fileName;

        $tempPath = sys_get_temp_dir() . '/' . Str::uuid();
        $file->save($tempPath);

        Storage::disk($disk)->put($path, file_get_contents($tempPath));
        if (file_exists($tempPath)) {
            unlink($tempPath);
        }

        $attachments->push(new Attachment(
            path: $path,
            disk: $disk,
            mimeType: $mimeType,
            filename: $fileName,
        ));
    }

    public function identifier(): string
    {
        return "telegram:{$this->chatId}";
    }

    public function userIdentifier(): ?string
    {
        return $this->isDm() ? $this->identifier() : null;
    }

    public function acknowledge(): void
    {
        try {
            app(Nutgram::class)->sendChatAction(ChatAction::TYPING, chat_id: $this->chatId);
        } catch (Throwable $e) {
            Log::warning('Telegram typing indicator failed', ['error' => $e->getMessage()]);
        }
    }

    public function send(string $message): void
    {
        $html = (new CommonMarkConverter)->convert($message)->getContent();
        $html = preg_replace('/<li>/', '<li>• ', $html);
        $html = strip_tags($html, '<b><strong><i><em><u><s><a><code><pre><blockquote>');
        $html = trim($html);

        app(Nutgram::class)->sendMessage($html, chat_id: $this->chatId, parse_mode: ParseMode::HTML);
    }

    public function sendAudio(Attachment $attachment, ?string $caption = null): void
    {
        // Nutgram requires a local file stream — read from disk into a temp file.
        // This assumes the attachments disk is readable; remote-only disks (e.g. S3) will fail here.
        $contents = Storage::disk($attachment->disk)->get($attachment->path);
        $tempPath = sys_get_temp_dir() . '/' . basename($attachment->path);
        file_put_contents($tempPath, $contents);

        app(Nutgram::class)->sendVoice(
            voice: InputFile::make(fopen($tempPath, 'r')),
            chat_id: $this->chatId,
        );

        unlink($tempPath);
    }

    public function sendImage(Attachment $attachment): void
    {
        // Nutgram requires a local file stream — read from disk into a temp file.
        // This assumes the attachments disk is readable; remote-only disks (e.g. S3) will fail here.
        $contents = Storage::disk($attachment->disk)->get($attachment->path);
        $tempPath = sys_get_temp_dir() . '/' . basename($attachment->path);
        file_put_contents($tempPath, $contents);

        app(Nutgram::class)->sendPhoto(
            photo: InputFile::make(fopen($tempPath, 'r'), basename($attachment->path)),
            chat_id: $this->chatId,
        );

        unlink($tempPath);
    }

    private function isDm(): bool
    {
        return $this->chatId > 0;
    }
}
