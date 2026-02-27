<?php

namespace LaraClaw\Channels;

use DirectoryTree\ImapEngine\Attachment as ImapAttachment;
use DirectoryTree\ImapEngine\Enums\ImapFlag;
use DirectoryTree\ImapEngine\Laravel\Facades\Imap;
use DirectoryTree\ImapEngine\MessageInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LaraClaw\Channels\DTOs\Attachment;
use LaraClaw\Channels\DTOs\AttachmentType;
use LaraClaw\Mail\ChannelReply;
use League\CommonMark\CommonMarkConverter;

use function LaraClaw\Support\stripHtml;

class EmailChannel extends Channel
{
    /** @var array<int, array{disk: string, path: string}> */
    private array $pendingAttachments = [];

    public function __construct(
        private string $senderEmail,
        private ?string $senderName,
        private ?string $subject,
        private ?string $messageId,
        private string $threadId,
        private int $uid,
        private string $mailbox,
        ?string $text = null,
        ?Collection $attachments = null,
    ) {
        $this->messageText = $text;
        $this->messageAttachments = $attachments ?? collect();
    }

    public static function fromMessage(MessageInterface $message): self
    {
        $attachments = collect();
        $disk = config('laraclaw.filesystem.attachments_disk', 'local');
        $basePath = config('laraclaw.filesystem.attachments_path', 'attachments') . '/email';

        foreach ($message->attachments() as $attachment) {
            self::downloadAttachment($attachment, $disk, $basePath, $attachments);
        }

        $from = $message->from();

        return new self(
            senderEmail: $from?->email() ?? 'unknown',
            senderName: $from?->name(),
            subject: $message->subject(),
            messageId: $message->messageId(),
            threadId: self::resolveThreadId($message),
            uid: $message->uid(),
            mailbox: config('laraclaw.channels.email.imap.mailbox', 'default'),
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
            type: AttachmentType::fromMimeType($mimeType),
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

    public function sendPhoto(string $disk, string $path): void
    {
        $this->pendingAttachments[] = ['disk' => $disk, 'path' => $path];
    }

    public function sendFiles(array $files): void
    {
        foreach ($files as $file) {
            $this->pendingAttachments[] = $file;
        }
    }

    public function send(string $message): void
    {
        $mailable = new ChannelReply(
            body: (new CommonMarkConverter)->convert($message)->getContent(),
            inReplyTo: $this->messageId,
        );

        foreach ($this->pendingAttachments as $file) {
            $mailable->attach(
                Storage::disk($file['disk'])->path($file['path']),
                ['as' => basename($file['path'])],
            );
        }

        $mailable->to($this->senderEmail, $this->senderName)
            ->subject('Re: ' . ($this->subject ?? 'No Subject'));

        Mail::send($mailable);

        $this->markSeen();
    }

    public function sendAudio(string $filePath, ?string $caption = null): void
    {
        $mailable = new ChannelReply(
            body: $caption ? (new CommonMarkConverter)->convert($caption)->getContent() : '',
            inReplyTo: $this->messageId,
        );

        $mailable->to($this->senderEmail, $this->senderName)
            ->subject('Re: ' . ($this->subject ?? 'No Subject'))
            ->attach($filePath, ['as' => 'voice.mp3', 'mime' => 'audio/mpeg']);

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
