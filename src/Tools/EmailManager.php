<?php

namespace Laraclaw\Tools;

use DirectoryTree\ImapEngine\Address;
use DirectoryTree\ImapEngine\FolderInterface;
use DirectoryTree\ImapEngine\Laravel\Facades\Imap;
use DirectoryTree\ImapEngine\MailboxInterface;
use DirectoryTree\ImapEngine\MessageInterface;
use Exception;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laraclaw\DTOs\IncomingMessage;
use Laravel\Ai\Tools\Request;
use Override;
use Stringable;

use function Laraclaw\Support\stripHtml;

/**
 * Agent tool for reading, sending, and managing email via IMAP and Laravel Mail.
 */
class EmailManager extends BaseTool
{
    private const int MAX_LIST = 20;

    private const int MAX_BODY = 50000;

    protected array $requires = [
        'read' => ['uid'],
        'send' => ['to', 'subject', 'body'],
        'reply' => ['uid', 'body'],
        'move' => ['uid', 'folder'],
        'label' => ['uid', 'folder'],
        'mark_read' => ['uid'],
        'mark_unread' => ['uid'],
        'create_folder' => ['folder'],
    ];

    /**
     * Bind the inbound message and IMAP mailbox name, then register the delete approval prompts.
     */
    public function __construct(
        protected IncomingMessage $message,
        private readonly string $mailbox,
    ) {
        $this->requiresApproval['delete'] = function (Request $request): string {
            $uids = $this->oneOrMany($request, 'uid', 'uids')->implode(', ');
            $folder = $request->string('folder')->value() ?: 'INBOX';

            return "Delete messages {$uids} from {$folder}?";
        };

        $this->requiresApproval['delete_folder'] = fn (Request $request): string => 'Delete folder ' . $this->oneOrMany($request, 'folder', 'folders')->implode(', ') . '?';
    }

    /**
     * Return the tool description shown to the agent.
     */
    public function description(): Stringable|string
    {
        return 'Manage email. Operations: ' . implode(', ', $this->operations())
            . '. Use inbox to list messages, read to view one, send/reply to compose, delete/move to organize, label to tag without removing from source folder, create_folder/delete_folder to manage folders. For move/label: set source_folder when the message is not in INBOX. Use the folders operation to list available folders.';
    }

    /**
     * Define the input schema for this tool.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'operation' => $schema->string()->required()->description('The operation to perform: ' . implode(', ', $this->operations())),
            'uid' => $schema->integer()->description('Message UID (required for read, reply, delete, move, mark_read, mark_unread)'),
            'uids' => $schema->array()->items($schema->integer())->description('Multiple message UIDs for batch delete'),
            'folder' => $schema->string()->description('Folder name (default: INBOX). For move/label, this is the destination folder. For create_folder/delete_folder, this is the folder to create/delete.'),
            'folders' => $schema->array()->items($schema->string())->description('Multiple folder names for batch delete_folder'),
            'source_folder' => $schema->string()->description('Source folder for move/label operations (default: INBOX). Set this when moving messages from a folder other than INBOX.'),
            'to' => $schema->array()->items($schema->string())->description('Recipient email addresses (required for send)'),
            'cc' => $schema->array()->items($schema->string())->description('CC email addresses'),
            'bcc' => $schema->array()->items($schema->string())->description('BCC email addresses'),
            'subject' => $schema->string()->description('Email subject (required for send)'),
            'body' => $schema->string()->description('Email body text (required for send and reply)'),
            'attachments' => $schema->array()->items(
                $schema->object([
                    'disk' => $schema->string()->description('Storage disk (e.g. "local")'),
                    'path' => $schema->string()->description('File path on the disk'),
                    'filename' => $schema->string()->description('Optional display filename'),
                    'mime_type' => $schema->string()->description('Optional MIME type'),
                ])
            )->description('Files to attach (use disk/path from [Attached files] metadata in the conversation)'),
            'search' => $schema->string()->description('Plain text search for inbox. Matches anywhere in the message (subject, sender, body). Do NOT use Gmail query syntax like "from:" or "subject:", just plain words. To filter by sender, use from_filter instead.'),
            'from_filter' => $schema->string()->description('Filter inbox by sender email or name (partial match, e.g. "netflix" matches "info@members.netflix.com")'),
            'limit' => $schema->integer()->description('Max messages to return for inbox (default 10, max 20)'),
        ];
    }

    /**
     * Run the requested operation and catch any email exceptions as a string error.
     */
    #[Override]
    public function handle(Request $request): Stringable|string
    {
        try {
            // Attachments are the one argument that reads files off a disk, and the model
            // picks both the disk and the path. Clear them through the same filesystem rules
            // FileManager applies before any operation gets a chance to send them out.
            if ($error = $this->validateAttachments($request)) {
                return $error;
            }

            return parent::handle($request);
        } catch (Exception $e) {
            Log::error('EmailManager error', ['exception' => $e]);

            return "Email operation failed: {$e->getMessage()}";
        }
    }

    /**
     * Return the list of supported operation names.
     */
    protected function operations(): array
    {
        return ['inbox', 'read', 'send', 'reply', 'delete', 'move', 'label', 'mark_read', 'mark_unread', 'folders', 'create_folder', 'delete_folder'];
    }

    /**
     * List recent messages in a folder, with optional text and sender filters.
     */
    protected function inbox(Request $request): string
    {
        $query = $this->folder($request)->messages()->leaveUnread()->withHeaders()->withFlags()->withSize();

        if ($from = $request['from_filter'] ?? null) {
            $query->from($from);
        }

        if ($search = $request['search'] ?? null) {
            $query->text($search);
        }

        $messages = collect($query->newest()->limit(min((int) ($request['limit'] ?? 10), self::MAX_LIST))->get())
            ->map(fn (MessageInterface $message): array => $this->summarize($message));

        return $messages->isEmpty() ? 'No messages found.' : $messages->toJson(JSON_PRETTY_PRINT);
    }

    /**
     * Fetch and return the full content of a single message by UID.
     */
    protected function read(Request $request): string
    {
        $message = $this->find($request, withBody: true);

        if (! $message instanceof MessageInterface) {
            return $this->notFound($request);
        }

        $body = $message->text() ?? stripHtml($message->html()) ?? '(no body)';

        return json_encode([
            ...$this->summarize($message),
            'to' => collect($message->to())->map(fn (Address $address): array => $address->toArray())->all(),
            'cc' => collect($message->cc())->map(fn (Address $address): array => $address->toArray())->all(),
            'message_id' => $message->messageId(),
            'attachment_count' => $message->attachmentCount(),
            'flags' => $message->flags(),
            'body' => Str::limit($body, self::MAX_BODY, "\n\n[Truncated: body exceeds 50KB]"),
        ], JSON_PRETTY_PRINT);
    }

    /**
     * Compose and send a new email.
     */
    protected function send(Request $request): string
    {
        $to = (array) $request['to'];

        $this->compose($request['body'], $to, $request['subject'], $request);

        return 'Email sent to ' . implode(', ', $to) . " with subject \"{$request['subject']}\".";
    }

    /**
     * Reply to an existing message and set the thread headers so it appears as a reply.
     */
    protected function reply(Request $request): string
    {
        $original = $this->find($request, withBody: true);

        if (! $original instanceof MessageInterface) {
            return $this->notFound($request);
        }

        $replyTo = $original->replyTo() ?? $original->from();

        if (! $replyTo instanceof Address) {
            return 'Cannot determine reply address for this message.';
        }

        $subject = Str::of($original->subject() ?? 'No Subject')->when(
            fn ($subject): bool => ! $subject->lower()->startsWith('re:'),
            fn ($subject) => $subject->prepend('Re: '),
        )->value();

        $to = $request->array('to') ?: [$replyTo->email()];
        $messageId = $original->messageId();

        $this->compose($request['body'], $to, $subject, $request, function ($mail) use ($messageId): void {
            if ($messageId) {
                $mail->getHeaders()->addTextHeader('In-Reply-To', $messageId);
                $mail->getHeaders()->addTextHeader('References', $messageId);
            }
        });

        $original->markAnswered();

        return 'Reply sent to ' . implode(', ', $to) . " with subject \"{$subject}\".";
    }

    /**
     * Permanently delete one or more messages after user confirmation.
     */
    protected function delete(Request $request): string
    {
        $uids = $this->oneOrMany($request, 'uid', 'uids');

        if ($uids->isEmpty()) {
            return 'The "uid" or "uids" parameter is required for the delete operation.';
        }

        $folder = $this->folder($request);

        return $uids
            ->map(function (int $uid) use ($folder): string {
                if (! $folder->messages()->find($uid) instanceof MessageInterface) {
                    return "UID {$uid}: not found";
                }

                $folder->messages()->destroy($uid, expunge: true);

                return "UID {$uid}: deleted";
            })
            ->implode('; ') . '.';
    }

    /**
     * Move a message from one folder to another.
     */
    protected function move(Request $request): string
    {
        $folder = $this->folder($request, 'source_folder');

        if (! $this->find($request, 'source_folder') instanceof MessageInterface) {
            return $this->notFound($request, 'source_folder');
        }

        $folder->messages()->uid((int) $request['uid'])->move($request['folder'], expunge: true);

        return "Message {$request['uid']} moved from {$folder->path()} to {$request['folder']}.";
    }

    /**
     * Copy a message to a label or folder while keeping it in the source folder.
     */
    protected function label(Request $request): string
    {
        $folder = $this->folder($request, 'source_folder');

        if (! $this->find($request, 'source_folder') instanceof MessageInterface) {
            return $this->notFound($request, 'source_folder');
        }

        $folder->messages()->uid((int) $request['uid'])->copy($request['folder']);

        return "Label \"{$request['folder']}\" applied to message {$request['uid']} (message kept in {$folder->path()}).";
    }

    /**
     * Mark a message as read by UID.
     */
    protected function markRead(Request $request): string
    {
        return $this->flag($request, 'markRead', 'read');
    }

    /**
     * Mark a message as unread by UID.
     */
    protected function markUnread(Request $request): string
    {
        return $this->flag($request, 'markUnread', 'unread');
    }

    /**
     * List all folders in the configured mailbox.
     */
    protected function folders(Request $request): string
    {
        return collect($this->mailbox()->folders()->get())
            ->map(fn (FolderInterface $folder): array => ['path' => $folder->path(), 'name' => $folder->name()])
            ->toJson(JSON_PRETTY_PRINT);
    }

    /**
     * Create a new folder in the mailbox.
     */
    protected function createFolder(Request $request): string
    {
        $this->mailbox()->folders()->create($request['folder']);

        return "Folder \"{$request['folder']}\" created.";
    }

    /**
     * Delete one or more folders after user confirmation.
     */
    protected function deleteFolder(Request $request): string
    {
        $folders = $this->oneOrMany($request, 'folder', 'folders');

        if ($folders->isEmpty()) {
            return 'The "folder" or "folders" parameter is required for the delete_folder operation.';
        }

        $mailbox = $this->mailbox();

        return $folders
            ->map(function (string $folder) use ($mailbox): string {
                try {
                    $mailbox->folders()->findOrFail($folder)->delete();

                    return "{$folder}: deleted";
                } catch (Exception $e) {
                    return "{$folder}: {$e->getMessage()}";
                }
            })
            ->implode('; ') . '.';
    }

    /**
     * Set a read flag on the message the request names.
     */
    private function flag(Request $request, string $method, string $state): string
    {
        $message = $this->find($request);

        if (! $message instanceof MessageInterface) {
            return $this->notFound($request);
        }

        $message->{$method}();

        return "Message {$request['uid']} marked as {$state}.";
    }

    /**
     * Build and send a mail message, applying recipients, headers, and attachments.
     */
    private function compose(string $body, array $to, string $subject, Request $request, ?callable $extra = null): void
    {
        $fromAddress = config("imap.mailboxes.{$this->mailbox}.username");

        Mail::raw($body, function ($mail) use ($to, $subject, $fromAddress, $request, $extra): void {
            $mail->to($to)->subject($subject);

            if ($fromAddress) {
                $mail->from($fromAddress);
            }

            if (! empty($request['cc'])) {
                $mail->cc($request['cc']);
            }

            if (! empty($request['bcc'])) {
                $mail->bcc($request['bcc']);
            }

            foreach ($this->message->attachments as $attachment) {
                $mail->attachData(
                    Storage::disk($attachment->disk)->get($attachment->path),
                    $attachment->filename ?? basename((string) $attachment->path),
                    ['mime' => $attachment->mimeType ?? 'application/octet-stream'],
                );
            }

            foreach ($this->requestedAttachments($request) as $item) {
                $mail->attachData(Storage::disk($item['disk'])->get($item['path']), $item['filename'], ['mime' => $item['mime_type']]);
            }

            if ($extra) {
                $extra($mail);
            }
        });
    }

    /**
     * Return the attachments the model asked for, normalized to a disk, path, filename and MIME type.
     *
     * @return Collection<int, array<string, string>>
     */
    private function requestedAttachments(Request $request): Collection
    {
        return collect((array) ($request['attachments'] ?? []))
            ->filter(fn ($item): bool => is_array($item) && ! empty($item['path']))
            ->map(fn (array $item): array => [
                'disk' => (string) ($item['disk'] ?? config('laraclaw.filesystem.attachments_disk', 'local')),
                'path' => (string) $item['path'],
                'filename' => (string) ($item['filename'] ?? basename((string) $item['path'])),
                'mime_type' => (string) ($item['mime_type'] ?? 'application/octet-stream'),
            ])
            ->values();
    }

    /**
     * Check every requested attachment against the filesystem rules.
     *
     * Returns an error string for the agent, or null when all of them are readable.
     */
    private function validateAttachments(Request $request): ?string
    {
        foreach ($this->requestedAttachments($request) as $item) {
            if ($error = $this->validateFileAccess($item['disk'], $item['path'])) {
                return "Cannot attach {$item['path']}: {$error}";
            }

            if (! Storage::disk($item['disk'])->exists($item['path'])) {
                return "Cannot attach {$item['path']}: file not found on disk \"{$item['disk']}\".";
            }
        }

        return null;
    }

    /**
     * Read the values of a batch argument, falling back to its singular twin.
     *
     * array() tolerates a missing key where plain $request['uids'] would throw
     * before the fallback ever ran.
     */
    private function oneOrMany(Request $request, string $one, string $many): Collection
    {
        return collect($request->array($many) ?: [$request[$one] ?? null])->filter()->values();
    }

    /**
     * Fetch the message the request names, or null when the folder has no such UID.
     */
    private function find(Request $request, string $folderKey = 'folder', bool $withBody = false): ?MessageInterface
    {
        $query = $this->folder($request, $folderKey)->messages();

        if ($withBody) {
            $query = $query->withHeaders()->withBody();
        }

        $message = $query->find((int) $request['uid']);

        return $message instanceof MessageInterface ? $message : null;
    }

    /**
     * Word a missing message the same way everywhere.
     */
    private function notFound(Request $request, string $folderKey = 'folder'): string
    {
        return "Message with UID {$request['uid']} not found in {$this->folderName($request, $folderKey)}.";
    }

    /**
     * Open the folder the request names, defaulting to the inbox.
     */
    private function folder(Request $request, string $key = 'folder'): FolderInterface
    {
        return $this->mailbox()->folders()->findOrFail($this->folderName($request, $key));
    }

    /**
     * Read the folder name from the request, defaulting to the inbox.
     */
    private function folderName(Request $request, string $key): string
    {
        return $request[$key] ?? 'INBOX';
    }

    /**
     * Open the configured mailbox.
     */
    private function mailbox(): MailboxInterface
    {
        return Imap::mailbox($this->mailbox);
    }

    /**
     * Build a compact summary array for a message suitable for listing.
     *
     * @return array<string, mixed>
     */
    private function summarize(MessageInterface $message): array
    {
        $from = $message->from();

        return [
            'uid' => $message->uid(),
            'subject' => $message->subject(),
            'from' => $from ? ['email' => $from->email(), 'name' => $from->name()] : null,
            'date' => $message->date()?->toIso8601String(),
            'is_read' => $message->isSeen(),
            'is_flagged' => $message->isFlagged(),
            'has_attachments' => $message->hasAttachments(),
            'size' => $message->size(),
        ];
    }
}
