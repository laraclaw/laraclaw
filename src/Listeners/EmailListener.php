<?php

namespace Laraclaw\Listeners;

use DirectoryTree\ImapEngine\Laravel\Events\MessageReceived;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laraclaw\Agents\Dispatcher;
use Laraclaw\Connectors\Email;
use Laraclaw\Models\Thread;
use Laraclaw\Services\Attachments;

/**
 * Handles incoming IMAP messages, validates them and queues the agent.
 */
class EmailListener
{
    /**
     * Inject the attachment writer and the dispatcher that queues the agent.
     */
    public function __construct(
        private readonly Attachments $attachments,
        private readonly Dispatcher $dispatcher,
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

        $message = Email::createIncomingMessageFrom($event->message, $this->attachments);

        // Flag it now so the next poll does not pick it up again.
        Email::markSeen($event->message->uid());

        // The reply threads under the original, which only the raw message knows.
        $this->dispatcher->queue($message, Thread::forMessage($message), Email::fromRawMessage($event->message));
    }
}
