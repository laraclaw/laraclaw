<?php

namespace Laraclaw\Listeners;

use DirectoryTree\ImapEngine\Laravel\Events\MessageReceived;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laraclaw\Agents\ChatBotAgent;
use Laraclaw\Approvals\ApprovalFlow;
use Laraclaw\Commands\CommandRegistry;
use Laraclaw\Connectors\Email;
use Laraclaw\Models\Thread;
use Laraclaw\Services\Attachments;
use Laraclaw\Services\ProcessedEmails;
use Laravel\Ai\Responses\AgentResponse;
use Throwable;

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
        private readonly ApprovalFlow $approvals,
        private readonly ProcessedEmails $processed,
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

        $identifier = $this->identifierFor($event);

        // The seen flag lives on the mail server and the work lives here, so the two
        // can always come apart. Claim the message first and let the claim, not the
        // flag, be what stops us from answering the same email twice.
        if (! $this->processed->claim($identifier)) {
            Email::markSeen($raw->uid());

            return;
        }

        $connector = Email::fromRawMessage($raw);
        $incomingMessage = Email::createIncomingMessageFrom($raw, $this->attachments);
        $thread = Thread::forMessage($incomingMessage);

        // Handle commands
        if ($command = $this->commands->match($incomingMessage->text ?? '')) {
            $command->handle($incomingMessage, $thread);

            $this->settle($identifier, $raw->uid());

            return;
        }

        // We need references to pass to the callback
        $attachments = $this->attachments;
        $approvals = $this->approvals;

        $agent = resolve(ChatBotAgent::class, ['message' => $incomingMessage, 'thread' => $thread]);

        // When the agent paused on a gated tool call, this email is the user's
        // answer to it and resumes the paused run instead of starting a new turn.
        $decisions = $approvals->decisionsFrom($thread, $incomingMessage);

        // Queue the agent response, on callback deliver the reply via email
        $queued = ($decisions ? $agent->queue($decisions) : $agent->queue(...$incomingMessage->toAgentInput()))
            ->then(function (AgentResponse $response) use ($thread, $connector, $incomingMessage, $attachments, $approvals): void {
                $thread->update(['conversation_id' => $response->conversationId]);

                $connector->reply(
                    thread: $thread,
                    text: $approvals->capture($thread, $response) ?? $response->text,
                    attachments: $attachments->outbound($incomingMessage->uuid)->getAll(),
                );
            })
            ->catch(function (Throwable $e) use ($thread, $connector, $approvals): void {
                // A stale resume leaves the thread pointing at a pause the conversation
                // no longer has. Clear it, or every later message reads as an answer.
                if ($notice = $approvals->recover($thread, $e)) {
                    $connector->reply($thread, $notice);

                    return;
                }

                Log::error('Email agent error', ['error' => $e->getMessage()]);
            });

        // The job is only pushed once the pending dispatch is released, so release it
        // here and let the push, rather than the end of this method, be the moment the
        // email counts as ours. A push that throws leaves the email unseen for a retry.
        try {
            unset($queued);
        } catch (Throwable $e) {
            Log::error('Laraclaw: failed to queue the agent for an incoming email', [
                'identifier' => $identifier,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $this->settle($identifier, $raw->uid());
    }

    /**
     * Record the handoff and only then tell the mail server to stop offering the message.
     */
    private function settle(string $identifier, int $uid): void
    {
        $this->processed->confirm($identifier);

        Email::markSeen($uid);
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
