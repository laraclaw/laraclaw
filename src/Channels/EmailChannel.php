<?php

namespace LaraClaw\Channels;

use DirectoryTree\ImapEngine\Attachment as ImapAttachment;
use DirectoryTree\ImapEngine\Enums\ImapFlag;
use DirectoryTree\ImapEngine\Laravel\Facades\Imap;
use DirectoryTree\ImapEngine\MessageInterface;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LaraClaw\Channels\Concerns\ConfirmsViaRedis;
use LaraClaw\Channels\Contracts\SupportsAudio;
use LaraClaw\Channels\Contracts\SupportsConfirmation;
use LaraClaw\Channels\Contracts\SupportsFiles;
use LaraClaw\Channels\Contracts\SupportsImages;
use LaraClaw\DTOs\Attachment;
use LaraClaw\Mail\ChannelReply;
use LaraClaw\Message;
use League\CommonMark\CommonMarkConverter;

use function LaraClaw\Support\stripHtml;

class EmailChannel extends Channel implements SupportsAudio, SupportsConfirmation, SupportsFiles, SupportsImages
{
    use ConfirmsViaRedis;

    /** @var Attachment[] */
    private array $replyAttachments = [];

    public function __construct(
        private string $senderEmail,
        private ?string $senderName,
        private ?string $subject,
        private ?string $messageId,
        private string $threadId,
        private int $uid,
        private string $mailbox,
    ) {}

    public static function from(MessageInterface $message): \LaraClaw\Message
    {
        $attachments = collect();
        $disk = config('laraclaw.filesystem.attachments_disk', 'local');
        $basePath = config('laraclaw.filesystem.attachments_path', 'attachments') . '/email';

        foreach ($message->attachments() as $attachment) {
            self::downloadAttachment($attachment, $disk, $basePath, $attachments);
        }

        $from = $message->from();

        return new Message(
            channel: new self(
                senderEmail: $from?->email() ?? 'unknown',
                senderName: $from?->name(),
                subject: $message->subject(),
                messageId: $message->messageId(),
                threadId: self::resolveThreadId($message),
                uid: $message->uid(),
                mailbox: config('laraclaw.channels.email.imap.mailbox', 'default'),
            ),
            text: $message->text() ?? stripHtml($message->html()),
            attachments: $attachments,
        );
    }

    private static function downloadAttachment(ImapAttachment $attachment, string $disk, string $basePath, Collection $attachments): void
    {
        $filename = $attachment->filename() ?? 'attachment.' . ($attachment->extension() ?? 'bin');
        $mimeType = $attachment->contentType();
        $contents = $attachment->contents();
        $path = $basePath . '/' . Str::uuid() . '/' . $filename;

        Storage::disk($disk)->put($path, $contents);

        $attachments->push(new Attachment(
            path: $path,
            disk: $disk,
            mimeType: $mimeType,
            filename: $filename,
        ));
    }

    private static function resolveThreadId(MessageInterface $message): string
    {
        // References lists all Message-IDs oldest first — root is first entry
        // In-Reply-To points to direct parent (single-reply chains)
        return self::firstMessageId($message->header('References')?->getRawValue())
            ?? self::firstMessageId($message->header('In-Reply-To')?->getRawValue())
            ?? $message->messageId()
            ?? Str::uuid()->toString();
    }

    private static function firstMessageId(?string $header): ?string
    {
        if ($header && preg_match('/<([^>]+)>/', $header, $m)) {
            return $m[1];
        }

        return null;
    }

    public function identifier(): string
    {
        return "email:{$this->threadId}";
    }

    public function userIdentifier(): string
    {
        return "email:{$this->senderEmail}";
    }

    public function sendImage(Attachment $attachment): void
    {
        $this->replyAttachments[] = $attachment;
    }

    public function sendFile(Attachment $attachment): void
    {
        $this->replyAttachments[] = $attachment;
    }

    public function send(string $message): void
    {
        $mailable = new ChannelReply(
            body: (new CommonMarkConverter)->convert($message)->getContent(),
            inReplyTo: $this->messageId,
        );

        foreach ($this->replyAttachments as $attachment) {
            $mailable->attach(
                Storage::disk($attachment->disk)->path($attachment->path),
                ['as' => $attachment->filename ?? basename($attachment->path)],
            );
        }

        $mailable->to($this->senderEmail, $this->senderName)
            ->subject('Re: ' . ($this->subject ?? 'No Subject'));

        Mail::send($mailable);

        $this->markSeen();
    }

    public function sendAudio(Attachment $attachment, ?string $caption = null): void
    {
        $mailable = new ChannelReply(
            body: $caption ? (new CommonMarkConverter)->convert($caption)->getContent() : '',
            inReplyTo: $this->messageId,
        );

        $mailable->to($this->senderEmail, $this->senderName)
            ->subject('Re: ' . ($this->subject ?? 'No Subject'))
            ->attach(
                Storage::disk($attachment->disk)->path($attachment->path),
                ['as' => $attachment->filename ?? 'voice.mp3', 'mime' => $attachment->mimeType ?? 'audio/mpeg'],
            );

        Mail::send($mailable);

        $this->markSeen();
    }

    private function markSeen(): void
    {
        Imap::mailbox($this->mailbox)
            ->inbox()
            ->messages()
            ->find($this->uid)
            ?->flag(ImapFlag::Seen, '+');
    }
}
