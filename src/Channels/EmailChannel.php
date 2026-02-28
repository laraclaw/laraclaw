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
use LaraClaw\Channels\Concerns\ChecksRedisForConfirmations;
use LaraClaw\Channels\Contracts\SupportsConfirmation;
use LaraClaw\DTOs\Attachment;
use LaraClaw\Mail\ChannelReply;
use LaraClaw\Message;
use League\CommonMark\CommonMarkConverter;

use function LaraClaw\Support\stripHtml;

class EmailChannel extends Channel implements SupportsConfirmation
{
    use ChecksRedisForConfirmations;

    public readonly string $name = 'email';

    /** @var Attachment[] */
    private array $replyAttachments = [];

    /**
     * Create a new EmailChannel instance.
     */
    public function __construct(
        private string $senderEmail,
        private ?string $senderName,
        private ?string $subject,
        private ?string $messageId,
        private string $threadId,
        private int $uid,
        private string $mailbox,
    ) {}

    /**
     * Build a Message from a raw IMAP message, downloading any
     * attachments to storage.
     */
    public static function parseIncomingMessage(MessageInterface $message): Message
    {
        $disk = config('laraclaw.filesystem.attachments_disk', 'local');
        $basePath = config('laraclaw.filesystem.attachments_path', 'attachments').'/email';
        $from = $message->from();

        $channel = new self(
            senderEmail: $from?->email() ?? 'unknown',
            senderName: $from?->name(),
            subject: $message->subject(),
            messageId: $message->messageId(),
            threadId: self::resolveThreadId($message),
            uid: $message->uid(),
            mailbox: config('laraclaw.channels.email.imap.mailbox', 'default'),
        );

        return new Message(
            channel: $channel,
            conversationKey: $channel->conversationKey(),
            conversationIsDirectMessage: $channel->conversationIsDirectMessage(),
            text: $message->text() ?? stripHtml($message->html()),
            attachments: collect($message->attachments())
                ->map(fn (ImapAttachment $a) => self::storeAttachment($a, $disk, $basePath)),
        );
    }

    /**
     * Save a single IMAP attachment to storage and return its DTO.
     */
    private static function storeAttachment(ImapAttachment $attachment, string $disk, string $basePath): Attachment
    {
        $filename = $attachment->filename() ?? 'attachment.'.($attachment->extension() ?? 'bin');
        $path = $basePath.'/'.Str::uuid().'/'.$filename;

        Storage::disk($disk)->put($path, $attachment->contents());

        return new Attachment(
            path: $path,
            disk: $disk,
            mimeType: $attachment->contentType(),
            filename: $filename,
        );
    }

    /**
     * Resolve the root thread ID from email headers, falling back
     * to the message ID or a fresh UUID.
     */
    private static function resolveThreadId(MessageInterface $message): string
    {
        return self::firstMessageId($message->header('References')?->getRawValue())
            ?? self::firstMessageId($message->header('In-Reply-To')?->getRawValue())
            ?? $message->messageId()
            ?? Str::uuid()->toString();
    }

    /**
     * Extract the first Message-ID value from a References or
     * In-Reply-To header string.
     */
    private static function firstMessageId(?string $header): ?string
    {
        return $header && preg_match('/<([^>]+)>/', $header, $m) ? $m[1] : null;
    }

    /**
     * Queue attachments to be included in the outgoing reply.
     */
    public function handleAttachments(Collection $attachments): void
    {
        $this->replyAttachments = array_merge($this->replyAttachments, $attachments->all());
    }

    /**
     * Send the text reply, including any queued attachments.
     */
    public function send(string $message): void
    {
        $mailable = new ChannelReply(
            body: $this->renderMarkdown($message),
            inReplyTo: $this->messageId,
        );

        foreach ($this->replyAttachments as $attachment) {
            $mailable->attach(
                Storage::disk($attachment->disk)->path($attachment->path),
                ['as' => $attachment->filename ?? basename($attachment->path)],
            );
        }

        $mailable->to($this->senderEmail, $this->senderName)
            ->subject('Re: '.($this->subject ?? 'No Subject'));

        Mail::send($mailable);

        $this->markSeen();
    }

    private function conversationKey(): string
    {
        return $this->senderEmail;
    }

    private function conversationIsDirectMessage(): bool
    {
        return true;
    }

    /**
     * Convert Markdown to HTML for use as the email body.
     */
    private function renderMarkdown(string $content): string
    {
        return (new CommonMarkConverter)->convert($content)->getContent();
    }

    /**
     * Flag the original IMAP message as seen so it is not
     * reprocessed on the next poll.
     */
    private function markSeen(): void
    {
        Imap::mailbox($this->mailbox)
            ->inbox()
            ->messages()
            ->find($this->uid)
            ?->flag(ImapFlag::Seen, '+');
    }
}
