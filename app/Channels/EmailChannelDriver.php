<?php

namespace App\Channels;

use App\Channels\Contracts\ChannelDriver;
use App\Channels\DTOs\Attachment;
use App\Channels\DTOs\AttachmentType;
use DirectoryTree\ImapEngine\Attachment as ImapAttachment;
use DirectoryTree\ImapEngine\MessageInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EmailChannelDriver implements ChannelDriver
{
    public function __construct(
        private string $senderEmail,
        private ?string $senderName,
        private ?string $subject,
        private ?string $bodyText,
        private ?string $messageId,
        /** @var Collection<int, Attachment> */
        private Collection $messageAttachments,
    ) {}

    public static function fromMessage(MessageInterface $message): self
    {
        $attachments = collect();
        $disk = config('laraclaw.attachments.disk', 'local');
        $basePath = config('laraclaw.attachments.path', 'laraclaw/attachments');

        foreach ($message->attachments() as $attachment) {
            self::downloadAttachment($attachment, $disk, $basePath, $attachments);
        }

        $from = $message->from();

        return new self(
            senderEmail: $from?->email() ?? 'unknown',
            senderName: $from?->name(),
            subject: $message->subject(),
            bodyText: $message->text() ?? self::stripHtml($message->html()),
            messageId: $message->messageId(),
            messageAttachments: $attachments,
        );
    }

    private static function downloadAttachment(
        ImapAttachment $attachment,
        string $disk,
        string $basePath,
        Collection $attachments,
    ): void {
        $filename = $attachment->filename() ?? 'attachment.' . ($attachment->extension() ?? 'bin');
        $mimeType = $attachment->contentType();
        $contents = $attachment->contents();
        $path = $basePath . '/' . Str::uuid() . '/' . $filename;

        Storage::disk($disk)->put($path, $contents);

        $attachments->push(new Attachment(
            type: AttachmentType::fromMimeType($mimeType),
            path: $path,
            disk: $disk,
            mimeType: $mimeType,
            size: strlen($contents),
            filename: $filename,
        ));
    }

    private static function stripHtml(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        return trim(strip_tags(html_entity_decode($html, ENT_QUOTES, 'UTF-8')));
    }

    public function platform(): string
    {
        return 'email';
    }

    public function conversationId(): string
    {
        return $this->messageId ?? $this->senderEmail;
    }

    public function senderId(): string
    {
        return $this->senderEmail;
    }

    public function text(): ?string
    {
        return $this->bodyText;
    }

    public function attachments(): Collection
    {
        return $this->messageAttachments;
    }

    public function reply(string $text): void
    {
        $mailable = new \App\Mail\ChannelReply(
            body: $text,
            inReplyTo: $this->messageId,
        );

        $mailable->to($this->senderEmail, $this->senderName)
            ->subject('Re: ' . ($this->subject ?? 'No Subject'));

        Mail::send($mailable);
    }

    public function sendTypingIndicator(): void
    {
        // No-op for email
    }
}
