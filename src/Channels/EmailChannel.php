<?php

namespace LaraClaw\Channels;

use LaraClaw\Channels\DTOs\Attachment;
use LaraClaw\Channels\DTOs\AttachmentType;
use LaraClaw\Mail\ChannelReply;
use DirectoryTree\ImapEngine\Attachment as ImapAttachment;
use DirectoryTree\ImapEngine\MessageInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

use function LaraClaw\Support\stripHtml;

class EmailChannel extends Channel
{
    public function __construct(
        private string $senderEmail,
        private ?string $senderName,
        private ?string $subject,
        private ?string $messageId,
        ?string $text = null,
        ?Collection $attachments = null,
    ) {
        $this->messageText = $text;
        $this->messageAttachments = $attachments ?? collect();
    }

    public static function fromMessage(MessageInterface $message): self
    {
        $attachments = collect();
        $disk = config('laraclaw.attachments.disk', 'local');
        $basePath = config('laraclaw.attachments.path', 'attachments').'/email';

        foreach ($message->attachments() as $attachment) {
            self::downloadAttachment($attachment, $disk, $basePath, $attachments);
        }

        $from = $message->from();

        return new self(
            senderEmail: $from?->email() ?? 'unknown',
            senderName: $from?->name(),
            subject: $message->subject(),
            messageId: $message->messageId(),
            text: $message->text() ?? stripHtml($message->html()),
            attachments: $attachments,
        );
    }

    private static function downloadAttachment(ImapAttachment $attachment, string $disk, string $basePath, Collection $attachments): void
    {
        $filename = $attachment->filename() ?? 'attachment.'.($attachment->extension() ?? 'bin');
        $mimeType = $attachment->contentType();
        $contents = $attachment->contents();
        $path = $basePath.'/'.Str::uuid().'/'.$filename;

        Storage::disk($disk)->put($path, $contents);

        $attachments->push(new Attachment(
            type: AttachmentType::fromMimeType($mimeType),
            path: $path,
            disk: $disk,
            mimeType: $mimeType,
            filename: $filename,
        ));
    }

    public function identifier(): string
    {
        return "email:{$this->senderEmail}";
    }

    public function send(string $message): void
    {
        $mailable = new ChannelReply(
            body: $message,
            inReplyTo: $this->messageId,
        );

        $mailable->to($this->senderEmail, $this->senderName)
            ->subject('Re: '.($this->subject ?? 'No Subject'));

        Mail::send($mailable);
    }

    public function sendAudio(string $filePath, ?string $caption = null): void
    {
        $mailable = new ChannelReply(
            body: $caption ?? '',
            inReplyTo: $this->messageId,
        );

        $mailable->to($this->senderEmail, $this->senderName)
            ->subject('Re: '.($this->subject ?? 'No Subject'))
            ->attach($filePath, ['as' => 'voice.mp3', 'mime' => 'audio/mpeg']);

        Mail::send($mailable);
    }
}
