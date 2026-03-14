<?php

namespace LaraClaw\Connectors;

use DirectoryTree\ImapEngine\Attachment as ImapAttachment;
use DirectoryTree\ImapEngine\Enums\ImapFlag;
use DirectoryTree\ImapEngine\Laravel\Facades\Imap;
use DirectoryTree\ImapEngine\MessageInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LaraClaw\Connectors\Concerns\ChecksRedisForConfirmations;
use LaraClaw\Connectors\Contracts\SupportsConfirmation;
use LaraClaw\DTOs\Attachment;
use LaraClaw\DTOs\IncomingMessage;
use LaraClaw\Enums\ConnectorType;
use LaraClaw\Mail\ConnectorReply;
use LaraClaw\Models\Thread;
use LaraClaw\Models\Account;
use LaraClaw\Services\Attachments;
use League\CommonMark\CommonMarkConverter;

use function LaraClaw\Support\stripHtml;

class Email extends Connector implements SupportsConfirmation
{
    use ChecksRedisForConfirmations;

    public ConnectorType $type { get { return ConnectorType::Email; } }

    /** @var Attachment[] */
    private array $attachments = [];

    public function __construct(
        private string $senderEmail,
        private ?string $senderName = null,
        private ?string $subject = null,
        private ?string $messageId = null,
    ) {}

    /**
     * Validate an incoming IMAP message. Throws if the message should not be processed.
     */
    public static function validateEvent(MessageInterface $message): void
    {
        if (! config('laraclaw.connectors.email.enabled')) {
            throw ValidationException::withMessages(['email' => 'CHANNEL_DISABLED']);
        }

        $botEmail = config('imap.mailboxes.' . config('laraclaw.connectors.email.imap.mailbox', 'default') . '.username');
        $fromEmail = $message->from()?->email() ?? 'unknown';

        if ($fromEmail === $botEmail) {
            throw ValidationException::withMessages(['email' => 'SELF_MESSAGE']);
        }

        if (! Account::query()->forConnector($fromEmail, ConnectorType::Email)->exists()) {
            throw ValidationException::withMessages(['email' => 'UNREGISTERED_ACCOUNT']);
        }

        if (config('laraclaw.connectors.email.verify_sender_dkim_and_spf') && ! self::passesAuthCheck($message)) {
            Log::warning('LaraClaw: email rejected due to failed DKIM or SPF authentication', [
                'from' => $fromEmail,
                'subject' => $message->subject() ?? '(no subject)',
            ]);

            throw ValidationException::withMessages(['email' => 'AUTH_CHECK_FAILED']);
        }

        if (blank($message->text()) && blank($message->html()) && $message->attachments() === []) {
            throw ValidationException::withMessages(['email' => 'EMPTY_MESSAGE']);
        }
    }

    /**
     * Build the reply connector from a raw IMAP message.
     */
    public static function fromRawMessage(MessageInterface $message): self
    {
        $from = $message->from();

        return new self(
            senderEmail: $from?->email() ?? 'unknown',
            senderName: $from?->name(),
            subject: $message->subject(),
            messageId: $message->messageId(),
        );
    }

    /**
     * Build an IncomingMessage DTO from a raw IMAP message,
     * saving any file attachments to storage.
     */
    public static function createIncomingMessageFrom(MessageInterface $message, Attachments $attachments): IncomingMessage
    {
        $uuid = (string) Str::uuid();

        return new IncomingMessage(
            text: $message->text() ?? stripHtml($message->html()),
            connector: ConnectorType::Email,
            key: $message->from()?->email() ?? 'unknown',
            isDirectMessage: true,
            attachments: self::saveAttachments($message, $attachments->inbound($uuid)),
            uuid: $uuid,
        );
    }

    /**
     * Build a minimal Email connector from just the sender email address.
     * Used for outbound messages that do not need threading headers.
     */
    public static function forKey(string $key): self
    {
        return new self(senderEmail: $key);
    }

    /**
     * Flag the original IMAP message as seen so it is not reprocessed on the next poll.
     */
    public static function markSeen(int $uid, ?string $mailbox = null): void
    {
        $mailbox ??= config('laraclaw.connectors.email.imap.mailbox', 'default');

        Imap::mailbox($mailbox)
            ->inbox()
            ->messages()
            ->find($uid)
            ?->flag(ImapFlag::Seen, '+');
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
     * Email conversations are always direct messages.
     */
    public static function isDirectMessage(string $key): bool
    {
        return true;
    }

    /**
     * Return true if both DKIM and SPF pass in the Authentication-Results header.
     */
    private static function passesAuthCheck(MessageInterface $message): bool
    {
        $authResults = $message->header('Authentication-Results')?->getRawValue() ?? '';

        $dkimPass = Str::contains($authResults, 'dkim=pass', ignoreCase: true);
        $spfPass = Str::contains($authResults, 'spf=pass', ignoreCase: true);

        return $dkimPass && $spfPass;
    }

    /**
     * Save all IMAP attachments to storage and return their DTOs.
     *
     * @return Attachment[]
     */
    private static function saveAttachments(MessageInterface $message, Attachments $attachments): array
    {
        return collect($message->attachments())
            ->map(fn (ImapAttachment $a): Attachment => self::storeAttachment($a, $attachments))
            ->toArray();
    }

    /**
     * Save a single IMAP attachment to storage and return its DTO.
     */
    private static function storeAttachment(ImapAttachment $attachment, Attachments $attachments): Attachment
    {
        $filename = $attachment->filename() ?? 'attachment.' . ($attachment->extension() ?? 'bin');
        $disk = config('laraclaw.filesystem.attachments_disk', 'local');
        $path = $attachments->set($filename, $attachment->contents());

        return new Attachment(
            path: $path,
            disk: $disk,
            mimeType: $attachment->contentType(),
            filename: $filename,
        );
    }

    private function handleAttachments(Collection $attachments): void
    {
        $this->attachments = array_merge($this->attachments, $attachments->all());
    }

    /**
     * Send the text reply, including any queued attachments.
     */
    private function send(string $message): void
    {
        $mailable = new ConnectorReply(
            body: $this->renderMarkdown($message),
            inReplyTo: $this->messageId,
        );

        foreach ($this->attachments as $attachment) {
            $mailable->attach(
                Storage::disk($attachment->disk)->path($attachment->path),
                ['as' => $attachment->filename ?? basename($attachment->path)],
            );
        }

        $mailable->to($this->senderEmail, $this->senderName)
            ->subject('Re: ' . ($this->subject ?? 'No Subject'));

        Mail::send($mailable);
    }

    /**
     * Convert Markdown to HTML for use as the email body.
     */
    private function renderMarkdown(string $content): string
    {
        return (new CommonMarkConverter)->convert($content)->getContent();
    }
}
