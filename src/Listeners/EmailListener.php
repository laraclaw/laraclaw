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

        // We need references to pass to the callback
        $attachments = $this->attachments;
        $approvals = $this->approvals;

        $agent = resolve(ChatBotAgent::class, ['message' => $incomingMessage, 'thread' => $thread]);

        // When the agent paused on a gated tool call, this email is the user's
        // answer to it and resumes the paused run instead of starting a new turn.
        $decisions = $approvals->decisionsFrom($thread, $incomingMessage);

        // Queue the agent response, on callback deliver the reply via email
        ($decisions ? $agent->queue($decisions) : $agent->queue(...$incomingMessage->toAgentInput()))
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
    }
}
