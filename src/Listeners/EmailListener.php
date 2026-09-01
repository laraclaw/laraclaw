<?php

namespace Laraclaw\Listeners;

use DirectoryTree\ImapEngine\Laravel\Events\MessageReceived;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laraclaw\Agents\ChatBotAgent;
use Laraclaw\Approvals\ApprovalFlow;
use Laraclaw\Commands\CommandRegistry;
use Laraclaw\Connectors\Email;
use Laraclaw\Enums\EmailClaim;
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
        $claim = $this->processed->claim($identifier);

        if ($claim !== EmailClaim::Granted) {
            if ($claim->shouldMarkSeen()) {
                Email::markSeen($raw->uid(), $event->mailbox);
            }

            return;
        }

        $connector = Email::fromRawMessage($raw);
        $incomingMessage = Email::createIncomingMessageFrom($raw, $this->attachments);
        $thread = Thread::forMessage($incomingMessage);

        // Handle commands
        if ($command = $this->commands->match($incomingMessage->text ?? '')) {
            $command->handle($incomingMessage, $thread);

            $this->settle($identifier, $raw->uid(), $event->mailbox);

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

        // Laravel pushes a queued job from the pending dispatch destructor, and it
        // exposes no method to fire it by hand, so dropping the last reference is
        // what sends the job. Doing it here, rather than letting the variable fall
        // out of scope at the end of the method, keeps the push inside the try and
        // makes the moment the email becomes ours something we can see and test.
        try {
            unset($queued);
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

        $this->settle($identifier, $raw->uid(), $event->mailbox);
    }

    /**
     * Record the handoff and only then tell the mail server to stop offering the message.
     */
    private function settle(string $identifier, int $uid, string $mailbox): void
    {
        $this->processed->confirm($identifier);

        Email::markSeen($uid, $mailbox);
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
