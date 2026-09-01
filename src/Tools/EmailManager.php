<?php

namespace Laraclaw\Tools;

use DirectoryTree\ImapEngine\Address;
use DirectoryTree\ImapEngine\FolderInterface;
use DirectoryTree\ImapEngine\Laravel\Facades\Imap;
use DirectoryTree\ImapEngine\MessageInterface;
use Exception;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
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

    protected array $requiresApproval = [];

    /**
     * Bind the inbound message and IMAP mailbox name, then register the delete approval prompts.
     */
    public function __construct(
        protected IncomingMessage $message,
        private readonly string $mailbox,
    ) {
        $this->requiresApproval['delete'] = function (Request $request): string {
            $uids = collect($request->array('uids') ?: [$request->string('uid')->value()])->filter();
            $folder = $request->string('folder')->value() ?: 'INBOX';

            return "Delete messages {$uids->implode(', ')} from {$folder}?";
        };

        $this->requiresApproval['delete_folder'] = function (Request $request): string {
            $folders = collect($request->array('folders') ?: [$request->string('folder')->value()])->filter();

            return "Delete folder {$folders->implode(', ')}?";
        };
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
            'search' => $schema->string()->description('Plain text search for inbox — matches anywhere in the message (subject, sender, body). Do NOT use Gmail query syntax like "from:" or "subject:" — just use plain words. To filter by sender, use from_filter instead.'),
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
        $folder = $this->getFolder($request['folder'] ?? 'INBOX');
        $limit = min((int) ($request['limit'] ?? 10), self::MAX_LIST);
        $search = $request['search'] ?? null;
        $fromFilter = $request['from_filter'] ?? null;

        $query = $folder->messages()->leaveUnread()->withHeaders()->withFlags()->withSize();

        if ($fromFilter) {
            $query->from($fromFilter);
        }

        if ($search) {
            $query->text($search);
        }

        $messages = $query->newest()->limit($limit)->get();

        $result = collect($messages)->map(fn (MessageInterface $m): array => $this->summarize($m));

        if ($result->isEmpty()) {
            return 'No messages found.';
        }

        return $result->toJson(JSON_PRETTY_PRINT);
    }

    /**
     * Fetch and return the full content of a single message by UID.
     */
    protected function read(Request $request): string
    {
        $uid = $request['uid'] ?? null;
        if ($uid === null) {
            return 'The "uid" parameter is required for the read operation.';
        }

        $folder = $this->getFolder($request['folder'] ?? 'INBOX');
        $message = $folder->messages()->withHeaders()->withBody()->find((int) $uid);

        if (! $message instanceof MessageInterface) {
            return "Message with UID {$uid} not found.";
        }

        $body = $message->text() ?? stripHtml($message->html()) ?? '(no body)';

        if (strlen($body) > self::MAX_BODY) {
            $body = substr($body, 0, self::MAX_BODY) . "\n\n[Truncated: body exceeds 50KB]";
        }

        $from = $message->from();
        $data = [
            'uid' => $message->uid(),
            'subject' => $message->subject(),
            'from' => $from ? ['email' => $from->email(), 'name' => $from->name()] : null,
            'to' => collect($message->to())->map(fn ($a): array => $a->toArray())->all(),
            'cc' => collect($message->cc())->map(fn ($a): array => $a->toArray())->all(),
            'date' => $message->date()?->toIso8601String(),
            'message_id' => $message->messageId(),
            'has_attachments' => $message->hasAttachments(),
            'attachment_count' => $message->attachmentCount(),
            'flags' => $message->flags(),
            'body' => $body,
        ];

        return json_encode($data, JSON_PRETTY_PRINT);
    }

    /**
     * Compose and send a new email.
     */
    protected function send(Request $request): string
    {
        $to = $request['to'] ?? null;
        $subject = $request['subject'] ?? null;
        $body = $request['body'] ?? null;

        if (empty($to)) {
            return 'The "to" parameter is required for the send operation.';
        }
        if ($subject === null) {
            return 'The "subject" parameter is required for the send operation.';
        }
        if ($body === null) {
            return 'The "body" parameter is required for the send operation.';
        }

        $this->compose($body, $to, $subject, $request);

        return 'Email sent to ' . implode(', ', (array) $to) . " with subject \"{$subject}\".";
    }

    /**
     * Reply to an existing message and set the thread headers so it appears as a reply.
     */
    protected function reply(Request $request): string
    {
        $uid = $request['uid'] ?? null;
        $body = $request['body'] ?? null;

        if ($uid === null) {
            return 'The "uid" parameter is required for the reply operation.';
        }
        if ($body === null) {
            return 'The "body" parameter is required for the reply operation.';
        }

        $folder = $this->getFolder($request['folder'] ?? 'INBOX');
        $original = $folder->messages()->withHeaders()->withBody()->find((int) $uid);

        if (! $original instanceof MessageInterface) {
            return "Message with UID {$uid} not found.";
        }

        $replyTo = $original->replyTo() ?? $original->from();
        if (! $replyTo instanceof Address) {
            return 'Cannot determine reply address for this message.';
        }

        $subject = $original->subject() ?? 'No Subject';
        if (! str_starts_with(strtolower($subject), 're:')) {
            $subject = 'Re: ' . $subject;
        }

        $to = $request->array('to') ?: [$replyTo->email()];
        $messageId = $original->messageId();

        $this->compose($body, $to, $subject, $request, function ($msg) use ($messageId): void {
            if ($messageId) {
                $msg->getHeaders()->addTextHeader('In-Reply-To', $messageId);
                $msg->getHeaders()->addTextHeader('References', $messageId);
            }
        });

        $original->markAnswered();

        return 'Reply sent to ' . implode(', ', (array) $to) . " with subject \"{$subject}\".";
    }

    /**
     * Permanently delete one or more messages after user confirmation.
     */
    protected function delete(Request $request): string
    {
        $uids = collect($request->array('uids') ?: [$request['uid'] ?? null])->filter()->values()->all();
        if ($uids === []) {
            return 'The "uid" or "uids" parameter is required for the delete operation.';
        }

        $folderName = $request['folder'] ?? 'INBOX';
        $folder = $this->getFolder($folderName);

        return collect($uids)
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
        $uid = $request['uid'] ?? null;
        $destination = $request['folder'] ?? null;

        if ($uid === null) {
            return 'The "uid" parameter is required for the move operation.';
        }
        if ($destination === null) {
            return 'The "folder" parameter is required for the move operation (destination folder).';
        }

        $sourceFolder = $request['source_folder'] ?? 'INBOX';
        $folder = $this->getFolder($sourceFolder);
        $message = $folder->messages()->find((int) $uid);

        if (! $message instanceof MessageInterface) {
            return "Message with UID {$uid} not found in {$sourceFolder}.";
        }

        $folder->messages()->uid((int) $uid)->move($destination, expunge: true);

        return "Message {$uid} moved from {$sourceFolder} to {$destination}.";
    }

    /**
     * Copy a message to a label or folder while keeping it in the source folder.
     */
    protected function label(Request $request): string
    {
        $uid = $request['uid'] ?? null;
        $destination = $request['folder'] ?? null;

        if ($uid === null) {
            return 'The "uid" parameter is required for the label operation.';
        }
        if ($destination === null) {
            return 'The "folder" parameter is required for the label operation (label/folder to apply).';
        }

        $sourceFolder = $request['source_folder'] ?? 'INBOX';
        $folder = $this->getFolder($sourceFolder);
        $message = $folder->messages()->find((int) $uid);

        if (! $message instanceof MessageInterface) {
            return "Message with UID {$uid} not found in {$sourceFolder}.";
        }

        $folder->messages()->uid((int) $uid)->copy($destination);

        return "Label \"{$destination}\" applied to message {$uid} (message kept in {$sourceFolder}).";
    }

    /**
     * Mark a message as read by UID.
     */
    protected function markRead(Request $request): string
    {
        $uid = $request['uid'] ?? null;
        if ($uid === null) {
            return 'The "uid" parameter is required for the mark_read operation.';
        }

        $folder = $this->getFolder($request['folder'] ?? 'INBOX');
        $message = $folder->messages()->find((int) $uid);

        if (! $message instanceof MessageInterface) {
            return "Message with UID {$uid} not found.";
        }

        $message->markRead();

        return "Message {$uid} marked as read.";
    }

    /**
     * Mark a message as unread by UID.
     */
    protected function markUnread(Request $request): string
    {
        $uid = $request['uid'] ?? null;
        if ($uid === null) {
            return 'The "uid" parameter is required for the mark_unread operation.';
        }

        $folder = $this->getFolder($request['folder'] ?? 'INBOX');
        $message = $folder->messages()->find((int) $uid);

        if (! $message instanceof MessageInterface) {
            return "Message with UID {$uid} not found.";
        }

        $message->markUnread();

        return "Message {$uid} marked as unread.";
    }

    /**
     * List all folders in the configured mailbox.
     */
    protected function folders(Request $request): string
    {
        $mailbox = Imap::mailbox($this->mailbox);
        $folders = $mailbox->folders()->get();

        return collect($folders)
            ->map(fn ($folder): array => ['path' => $folder->path(), 'name' => $folder->name()])
            ->toJson(JSON_PRETTY_PRINT);
    }

    /**
     * Create a new folder in the mailbox.
     */
    protected function createFolder(Request $request): string
    {
        $folder = $request['folder'] ?? null;
        if ($folder === null) {
            return 'The "folder" parameter is required for the create_folder operation.';
        }

        Imap::mailbox($this->mailbox)->folders()->create($folder);

        return "Folder \"{$folder}\" created.";
    }

    /**
     * Delete one or more folders after user confirmation.
     */
    protected function deleteFolder(Request $request): string
    {
        $folders = collect($request->array('folders') ?: [$request['folder'] ?? null])->filter()->values()->all();

        if ($folders === []) {
            return 'The "folder" or "folders" parameter is required for the delete_folder operation.';
        }

        $mailbox = Imap::mailbox($this->mailbox);

        return collect($folders)
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
     * Build and send a mail message, applying recipients, headers, and attachments.
     */
    private function compose(string $body, array $to, string $subject, Request $request, ?callable $extra = null): void
    {
        $fromAddress = config("imap.mailboxes.{$this->mailbox}.username");

        Mail::raw($body, function ($msg) use ($to, $subject, $fromAddress, $request, $extra): void {
            $msg->to($to);
            $msg->subject($subject);

            if ($fromAddress) {
                $msg->from($fromAddress);
            }

            if (! empty($request['cc'])) {
                $msg->cc($request['cc']);
            }

            if (! empty($request['bcc'])) {
                $msg->bcc($request['bcc']);
            }

            foreach ($this->message->attachments as $attachment) {
                $msg->attachData(
                    Storage::disk($attachment->disk)->get($attachment->path),
                    $attachment->filename ?? basename((string) $attachment->path),
                    ['mime' => $attachment->mimeType ?? 'application/octet-stream'],
                );
            }

            foreach ($this->requestedAttachments($request) as $item) {
                $msg->attachData(
                    Storage::disk($item['disk'])->get($item['path']),
                    $item['filename'],
                    ['mime' => $item['mime_type']],
                );
            }

            if ($extra) {
                $extra($msg);
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
     * Retrieve a mailbox folder by path, throwing if not found.
     */
    private function getFolder(string $path): FolderInterface
    {
        return Imap::mailbox($this->mailbox)->folders()->findOrFail($path);
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
