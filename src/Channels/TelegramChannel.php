<?php

namespace LaraClaw\Channels;

use LaraClaw\Channels\DTOs\Attachment;
use LaraClaw\Channels\DTOs\AttachmentType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\CommonMark\CommonMarkConverter;
use SergiX44\Nutgram\Nutgram;
use SergiX44\Nutgram\Telegram\Properties\ChatAction;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Internal\InputFile;
use SergiX44\Nutgram\Telegram\Types\Media\PhotoSize;
use SergiX44\Nutgram\Telegram\Types\Message\Message;

class TelegramChannel extends Channel
{
    public function __construct(
        private int|string $chatId,
        ?string $text = null,
        ?Collection $attachments = null,
    ) {
        $this->messageText = $text;
        $this->messageAttachments = $attachments ?? collect();
    }

    public static function fromMessage(Message $message, Nutgram $bot): self
    {
        $attachments = collect();
        $disk = config('laraclaw.attachments.disk', 'local');
        $basePath = config('laraclaw.attachments.path', 'attachments').'/telegram';

        // Photo (array of PhotoSize, pick largest)
        if (! empty($message->photo)) {
            $photo = collect($message->photo)->sortByDesc(fn (PhotoSize $p) => $p->file_size ?? 0)->first();
            self::downloadFile($bot, $photo->file_id, 'image/jpeg', null, $disk, $basePath, $attachments);
        }

        if ($message->audio) {
            self::downloadFile($bot, $message->audio->file_id, $message->audio->mime_type ?? 'audio/mpeg', $message->audio->file_name, $disk, $basePath, $attachments);
        }

        if ($message->voice) {
            self::downloadFile($bot, $message->voice->file_id, $message->voice->mime_type ?? 'audio/ogg', null, $disk, $basePath, $attachments);
        }

        if ($message->video) {
            self::downloadFile($bot, $message->video->file_id, $message->video->mime_type ?? 'video/mp4', $message->video->file_name, $disk, $basePath, $attachments);
        }

        if ($message->document) {
            self::downloadFile($bot, $message->document->file_id, $message->document->mime_type ?? 'application/octet-stream', $message->document->file_name, $disk, $basePath, $attachments);
        }

        return new self(
            chatId: $message->chat->id,
            text: $message->text ?? $message->caption ?? null,
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
        $path = $basePath.'/'.Str::uuid().'/'.$fileName;

        $tempPath = sys_get_temp_dir().'/'.Str::uuid();
        $file->save($tempPath);

        Storage::disk($disk)->put($path, file_get_contents($tempPath));
        if (file_exists($tempPath)) {
            unlink($tempPath);
        }

        $attachments->push(new Attachment(
            type: AttachmentType::fromMimeType($mimeType),
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

    public function acknowledge(): void
    {
        try {
            app(Nutgram::class)->sendChatAction(ChatAction::TYPING, chat_id: $this->chatId);
        } catch (\Throwable $e) {
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

    public function sendAudio(string $filePath): void
    {
        app(Nutgram::class)->sendVoice(
            voice: InputFile::make(fopen($filePath, 'r')),
            chat_id: $this->chatId,
        );
    }

    public function sendPhoto(string $disk, string $path): void
    {
        $contents = Storage::disk($disk)->get($path);
        $tempPath = sys_get_temp_dir().'/'.basename($path);
        file_put_contents($tempPath, $contents);

        app(Nutgram::class)->sendPhoto(
            photo: InputFile::make(fopen($tempPath, 'r'), basename($path)),
            chat_id: $this->chatId,
        );

        unlink($tempPath);
    }
}
