<?php

namespace Laraclaw\Listeners;

use DirectoryTree\ImapEngine\Laravel\Events\MessageReceived;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laraclaw\Commands\CommandRegistry;
use Laraclaw\Connectors\Email;
use Laraclaw\Jobs\RunAgentTurn;
use Laraclaw\Models\Thread;
use Laraclaw\Services\Attachments;

/**
 * Handles incoming IMAP messages, validates them and queues the agent.
 */
class EmailListener
{
    /**
     * Inject the attachment writer and the command registry consulted before dispatching to the agent.
     */
    public function __construct(
        private readonly Attachments $attachments,
        private readonly CommandRegistry $commands,
    ) {}

    /**
     * Validate the email, build the incoming message, and queue the agent for a reply.
     */
    public function __invoke(MessageReceived $event): void
    {
        $raw = $event->message;

        // Validate the incoming email
        try {
            Email::validateEvent($raw);
        } catch (ValidationException $e) {
            Log::debug('Email event skipped', ['code' => $e->getMessage()]);

            return;
        }

        $connector = Email::fromRawMessage($raw);
        $incomingMessage = Email::createIncomingMessageFrom($raw, $this->attachments);
        $thread = Thread::forMessage($incomingMessage);

        // Mark the email as seen so it is not reprocessed on the next poll
        Email::markSeen($raw->uid());

        // Handle commands
        if ($command = $this->commands->match($incomingMessage->text ?? '')) {
            $command->handle($incomingMessage, $thread);

            return;
        }

        // Hand the turn to the queue, carrying the connector because an email reply
        // has to quote the subject and message id of the mail being answered. The
        // agent is built inside the job so it reads the conversation the previous
        // turn saved, and the job holds a lock on the thread so two messages sent
        // at once run one after the other.
        RunAgentTurn::dispatch($thread, $incomingMessage, $connector);
    }
}
