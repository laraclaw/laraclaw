<?php

namespace LaraClaw\Tools;

use LaraClaw\Channels\Channel;
use DirectoryTree\ImapEngine\FolderInterface;
use DirectoryTree\ImapEngine\Laravel\Facades\Imap;
use DirectoryTree\ImapEngine\MessageInterface;
use Exception;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Mail;
use Laravel\Ai\Tools\Request;
use Stringable;

use function LaraClaw\Support\stripHtml;

class EmailManager extends BaseTool
{
    private const MAX_LIST = 20;

    private const MAX_BODY = 50000;

    protected array $requiresConfirmation = [
        'send' => 'Send email to {to} with subject "{subject}"?',
        'reply' => 'Send reply to message {uid}?',
    ];

    public function __construct(
        protected Channel $channel,
        private string $mailbox,
    ) {}

    protected function operations(): array
    {
        return ['inbox', 'read', 'send', 'reply', 'delete', 'move', 'label', 'mark_read', 'mark_unread', 'folders', 'create_folder', 'delete_folder'];
    }

    public function description(): Stringable|string
    {
        return 'Manage email. Operations: '.implode(', ', $this->operations())
            .'. Use inbox to list messages, read to view one, send/reply to compose, delete/move to organize, label to tag without removing from source folder, create_folder/delete_folder to manage folders. For move/label: set source_folder when the message is not in INBOX. Use the folders operation to list available folders.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'operation' => $schema->string()->required()->description('The operation to perform: '.implode(', ', $this->operations())),
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
            'search' => $schema->string()->description('Plain text search for inbox — matches anywhere in the message (subject, sender, body). Do NOT use Gmail query syntax like "from:" or "subject:" — just use plain words. To filter by sender, use from_filter instead.'),
            'from_filter' => $schema->string()->description('Filter inbox by sender email or name (partial match, e.g. "netflix" matches "info@members.netflix.com")'),
            'limit' => $schema->integer()->description('Max messages to return for inbox (default 10, max 20)'),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        try {
            return parent::handle($request);
        } catch (Exception $e) {
            return "Email operation failed: {$e->getMessage()}";
        }
    }

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

        $result = [];

        foreach ($messages as $message) {
            $result[] = $this->summarize($message);
        }

        if (empty($result)) {
            return 'No messages found.';
        }

        return json_encode($result, JSON_PRETTY_PRINT);
    }

    protected function read(Request $request): string
    {
        $uid = $request['uid'] ?? null;
        if ($uid === null) {
            return 'The "uid" parameter is required for the read operation.';
        }

        $folder = $this->getFolder($request['folder'] ?? 'INBOX');
        $message = $folder->messages()->withHeaders()->withBody()->find((int) $uid);

        if ($message === null) {
            return "Message with UID {$uid} not found.";
        }

        $body = $message->text() ?? stripHtml($message->html()) ?? '(no body)';

        if (strlen($body) > self::MAX_BODY) {
            $body = substr($body, 0, self::MAX_BODY)."\n\n[Truncated — body exceeds 50KB]";
        }

        $from = $message->from();
        $data = [
            'uid' => $message->uid(),
            'subject' => $message->subject(),
            'from' => $from ? ['email' => $from->email(), 'name' => $from->name()] : null,
            'to' => array_map(fn ($a) => $a->toArray(), $message->to()),
            'cc' => array_map(fn ($a) => $a->toArray(), $message->cc()),
            'date' => $message->date()?->toIso8601String(),
            'message_id' => $message->messageId(),
            'has_attachments' => $message->hasAttachments(),
            'attachment_count' => $message->attachmentCount(),
            'flags' => $message->flags(),
            'body' => $body,
        ];

        return json_encode($data, JSON_PRETTY_PRINT);
    }

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

        $fromAddress = config("imap.mailboxes.{$this->mailbox}.username");

        Mail::raw($body, function ($message) use ($to, $subject, $fromAddress, $request) {
            $message->to($to);
            $message->subject($subject);

            if ($fromAddress) {
                $message->from($fromAddress);
            }

            if (! empty($request['cc'])) {
                $message->cc($request['cc']);
            }

            if (! empty($request['bcc'])) {
                $message->bcc($request['bcc']);
            }
        });

        $recipients = implode(', ', (array) $to);

        return "Email sent to {$recipients} with subject \"{$subject}\".";
    }

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
        $message = $folder->messages()->withHeaders()->withBody()->find((int) $uid);

        if ($message === null) {
            return "Message with UID {$uid} not found.";
        }

        $replyTo = $message->replyTo() ?? $message->from();
        if ($replyTo === null) {
            return 'Cannot determine reply address for this message.';
        }

        $replyAddress = $replyTo->email();
        $messageId = $message->messageId();
        $subject = $message->subject() ?? 'No Subject';

        if (! str_starts_with(strtolower($subject), 're:')) {
            $subject = 'Re: '.$subject;
        }

        $fromAddress = config("imap.mailboxes.{$this->mailbox}.username");
        $to = ! empty($request['to']) ? $request['to'] : [$replyAddress];

        Mail::raw($body, function ($msg) use ($to, $subject, $messageId, $fromAddress, $request) {
            $msg->to($to);
            $msg->subject($subject);

            if ($fromAddress) {
                $msg->from($fromAddress);
            }

            if (! empty($request['cc'])) {
                $msg->cc($request['cc']);
            }

            if ($messageId) {
                $msg->getHeaders()->addTextHeader('In-Reply-To', $messageId);
                $msg->getHeaders()->addTextHeader('References', $messageId);
            }
        });

        $message->markAnswered();

        return 'Reply sent to '.implode(', ', (array) $to)." with subject \"{$subject}\".";
    }

    protected function delete(Request $request): string
    {
        $uids = ! empty($request['uids']) ? array_values($request['uids']) : (($request['uid'] ?? null) !== null ? [$request['uid']] : []);
        if ($uids === []) {
            return 'The "uid" or "uids" parameter is required for the delete operation.';
        }

        $folderName = $request['folder'] ?? 'INBOX';

        $count = count($uids);
        $message = $count === 1
            ? "Delete message {$uids[0]} from {$folderName}?"
            : "Delete {$count} messages (UIDs: ".implode(', ', $uids).") from {$folderName}?";

        if (! $this->channel->confirm($message)) {
            return 'Cancelled by user.';
        }

        $folder = $this->getFolder($folderName);
        $results = [];

        foreach ($uids as $uid) {
            $message = $folder->messages()->find((int) $uid);
            if ($message === null) {
                $results[] = "UID {$uid}: not found";
            } else {
                $folder->messages()->destroy((int) $uid, expunge: true);
                $results[] = "UID {$uid}: deleted";
            }
        }

        return implode('; ', $results).'.';
    }

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

        if ($message === null) {
            return "Message with UID {$uid} not found in {$sourceFolder}.";
        }

        $folder->messages()->uid((int) $uid)->move($destination, expunge: true);

        return "Message {$uid} moved from {$sourceFolder} to {$destination}.";
    }

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

        if ($message === null) {
            return "Message with UID {$uid} not found in {$sourceFolder}.";
        }

        $folder->messages()->uid((int) $uid)->copy($destination);

        return "Label \"{$destination}\" applied to message {$uid} (message kept in {$sourceFolder}).";
    }

    protected function markRead(Request $request): string
    {
        $uid = $request['uid'] ?? null;
        if ($uid === null) {
            return 'The "uid" parameter is required for the mark_read operation.';
        }

        $folder = $this->getFolder($request['folder'] ?? 'INBOX');
        $message = $folder->messages()->find((int) $uid);

        if ($message === null) {
            return "Message with UID {$uid} not found.";
        }

        $message->markRead();

        return "Message {$uid} marked as read.";
    }

    protected function markUnread(Request $request): string
    {
        $uid = $request['uid'] ?? null;
        if ($uid === null) {
            return 'The "uid" parameter is required for the mark_unread operation.';
        }

        $folder = $this->getFolder($request['folder'] ?? 'INBOX');
        $message = $folder->messages()->find((int) $uid);

        if ($message === null) {
            return "Message with UID {$uid} not found.";
        }

        $message->markUnread();

        return "Message {$uid} marked as unread.";
    }

    protected function folders(Request $request): string
    {
        $mailbox = Imap::mailbox($this->mailbox);
        $folders = $mailbox->folders()->get();

        $result = [];

        foreach ($folders as $folder) {
            $result[] = [
                'path' => $folder->path(),
                'name' => $folder->name(),
            ];
        }

        return json_encode($result, JSON_PRETTY_PRINT);
    }

    protected function createFolder(Request $request): string
    {
        $folder = $request['folder'] ?? null;
        if ($folder === null) {
            return 'The "folder" parameter is required for the create_folder operation.';
        }

        Imap::mailbox($this->mailbox)->folders()->create($folder);

        return "Folder \"{$folder}\" created.";
    }

    protected function deleteFolder(Request $request): string
    {
        $folders = ! empty($request['folders']) ? array_values($request['folders']) : (($request['folder'] ?? null) !== null ? [$request['folder']] : []);
        if ($folders === []) {
            return 'The "folder" or "folders" parameter is required for the delete_folder operation.';
        }

        $count = count($folders);
        $message = $count === 1
            ? "Delete folder \"{$folders[0]}\"?"
            : "Delete {$count} folders: ".implode(', ', $folders).'?';

        if (! $this->channel->confirm($message)) {
            return 'Cancelled by user.';
        }

        $mailbox = Imap::mailbox($this->mailbox);
        $results = [];

        foreach ($folders as $folder) {
            try {
                $mailbox->folders()->findOrFail($folder)->delete();
                $results[] = "{$folder}: deleted";
            } catch (Exception $e) {
                $results[] = "{$folder}: {$e->getMessage()}";
            }
        }

        return implode('; ', $results).'.';
    }

    private function getFolder(string $path): FolderInterface
    {
        return Imap::mailbox($this->mailbox)->folders()->findOrFail($path);
    }

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
