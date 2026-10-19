<?php

namespace Laraclaw\Listeners;

use DirectoryTree\ImapEngine\Laravel\Events\MessageReceived;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laraclaw\Agents\Dispatcher;
use Laraclaw\Connectors\Email;
use Laraclaw\Enums\EmailClaim;
use Laraclaw\Models\Thread;
use Laraclaw\Services\Attachments;
use Laraclaw\Services\ProcessedEmails;
use Throwable;

/**
 * Handles incoming IMAP messages, validates them and queues the agent.
 */
class EmailListener
{
    /**
     * Inject the attachment writer, the dispatcher that queues the agent, and the record of handled emails.
     */
    public function __construct(
        private readonly Attachments $attachments,
        private readonly Dispatcher $dispatcher,
        private readonly ProcessedEmails $processed,
    ) {}

    /**
     * Validate the email, build the incoming message, and queue the agent for a reply.
     */
    public function __invoke(MessageReceived $event): void
    {
        try {
            Email::validateEvent($event->message);
        } catch (ValidationException $e) {
            Log::debug('Email event skipped', ['code' => $e->getMessage()]);

            return;
        }

        $raw = $event->message;
        $identifier = $this->identifierFor($event);

        // The seen flag lives on the mail server and the work lives here, so the two
        // can always come apart. Claim the message first and let the claim, not the
        // flag, be what stops us from answering the same email twice.
        $claim = $this->processed->claim($identifier);

        if ($claim !== EmailClaim::Granted) {
            if ($claim->shouldMarkSeen()) {
                Email::markSeen($raw->uid(), $event->mailbox);
            }

            return;
        }

        $message = Email::createIncomingMessageFrom($raw, $this->attachments);

        // The dispatcher releases the pending job before it returns, so a push that
        // fails surfaces here, while the email is still unseen on the server.
        try {
            // The reply threads under the original, which only the raw message knows.
            $this->dispatcher->queue($message, Thread::forMessage($message), Email::fromRawMessage($raw));
        } catch (Throwable $e) {
            // Nothing was queued, so give the lease back and let the next poll retry
            // this message while it is still unseen on the server.
            $this->processed->release($identifier);

            Log::error('Laraclaw: failed to queue the agent for an incoming email', [
                'identifier' => $identifier,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        // Record the handoff and only then tell the mail server to stop offering the message.
        $this->processed->confirm($identifier);

        Email::markSeen($raw->uid(), $event->mailbox);
    }

    /**
     * Build the deduplication key for an email.
     *
     * The Message-ID is the only identifier that survives a mailbox moving the
     * message around. Fall back to the mailbox and UID when a sender omits it.
     */
    private function identifierFor(MessageReceived $event): string
    {
        return $event->message->messageId() ?? $event->mailbox . ':' . $event->message->uid();
    }
}
