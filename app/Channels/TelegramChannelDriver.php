<?php

namespace App\Channels;

use App\Channels\Contracts\ChannelDriver;
use App\Channels\DTOs\Attachment;
use App\Channels\DTOs\AttachmentType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\CommonMark\CommonMarkConverter;
use SergiX44\Nutgram\Nutgram;
use Illuminate\Support\Facades\Log;
use SergiX44\Nutgram\Telegram\Properties\ChatAction;
use SergiX44\Nutgram\Telegram\Properties\ParseMode;
use SergiX44\Nutgram\Telegram\Types\Media\PhotoSize;
use Throwable;
use SergiX44\Nutgram\Telegram\Types\Message\Message;

class TelegramChannelDriver implements ChannelDriver
{
    public function __construct(
        private int|string $chatId,
        private int|string $userId,
        private ?string $messageText,
        /** @var Collection<int, Attachment> */
        private Collection $messageAttachments,
    ) {}

    public static function fromMessage(Message $message, Nutgram $bot): self
    {
        $attachments = collect();
        $disk = config('laraclaw.attachments.disk', 'local');
        $basePath = config('laraclaw.attachments.path', 'laraclaw/attachments');

        // Photo (array of PhotoSize, pick largest)
        if (! empty($message->photo)) {
            $photo = collect($message->photo)->sortByDesc(fn (PhotoSize $p) => $p->file_size ?? 0)->first();
            self::downloadTelegramFile($bot, $photo->file_id, 'image/jpeg', $photo->file_size, null, $disk, $basePath, $attachments);
        }

        if ($message->audio) {
            self::downloadTelegramFile($bot, $message->audio->file_id, $message->audio->mime_type, $message->audio->file_size, $message->audio->file_name, $disk, $basePath, $attachments);
        }

        if ($message->voice) {
            self::downloadTelegramFile($bot, $message->voice->file_id, $message->voice->mime_type, $message->voice->file_size, null, $disk, $basePath, $attachments);
        }

        if ($message->video) {
            self::downloadTelegramFile($bot, $message->video->file_id, $message->video->mime_type, $message->video->file_size, $message->video->file_name, $disk, $basePath, $attachments);
        }

        if ($message->document) {
            self::downloadTelegramFile($bot, $message->document->file_id, $message->document->mime_type, $message->document->file_size, $message->document->file_name, $disk, $basePath, $attachments);
        }

        return new self(
            chatId: $message->chat->id,
            userId: $message->from->id,
            messageText: $message->text ?? $message->caption ?? null,
            messageAttachments: $attachments,
        );
    }

    private static function downloadTelegramFile(
        Nutgram $bot,
        string $fileId,
        ?string $mimeType,
        ?int $fileSize,
        ?string $fileName,
        string $disk,
        string $basePath,
        Collection $attachments,
    ): void {
        $file = $bot->getFile($fileId);

        if (! $file) {
            return;
        }

        $mimeType ??= 'application/octet-stream';
        $fileName ??= basename($file->file_path ?? $fileId);
        $path = $basePath . '/' . Str::uuid() . '/' . $fileName;

        $tempPath = sys_get_temp_dir() . '/' . Str::uuid();
        $file->save($tempPath);

        Storage::disk($disk)->put($path, file_get_contents($tempPath));
        @unlink($tempPath);

        $attachments->push(new Attachment(
            type: AttachmentType::fromMimeType($mimeType),
            path: $path,
            disk: $disk,
            mimeType: $mimeType,
            size: $fileSize,
            filename: $fileName,
        ));
    }

    public function platform(): string
    {
        return 'telegram';
    }

    public function conversationId(): string
    {
        return (string) $this->chatId;
    }

    public function senderId(): string
    {
        return (string) $this->userId;
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
        $html = (new CommonMarkConverter)->convert($text)->getContent();
        $html = preg_replace('/<li>/', '<li>• ', $html);
        $html = strip_tags($html, '<b><strong><i><em><u><s><a><code><pre><blockquote>');
        $html = trim($html);

        app(Nutgram::class)->sendMessage($html, chat_id: $this->chatId, parse_mode: ParseMode::HTML);
    }

    public function sendTypingIndicator(): void
    {
        try {
            app(Nutgram::class)->sendChatAction(ChatAction::TYPING, chat_id: $this->chatId);
        } catch (Throwable $e) {
            Log::warning('Telegram typing indicator failed', ['error' => $e->getMessage()]);
        }
    }
}
