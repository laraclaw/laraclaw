<?php

namespace Laraclaw\Listeners;

use DirectoryTree\ImapEngine\Laravel\Events\MessageReceived;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laraclaw\Agents\ChatBotAgent;
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

        // Check if the email is a reply to a pending confirmation
        if ($connector->resolvePendingConfirmation($incomingMessage)) {
            return;
        }

        // Handle commands
        if ($command = $this->commands->match($incomingMessage->text ?? '')) {
            $command->handle($incomingMessage, $thread);

            return;
        }

        // We need a reference to pass to the callback
        $attachments = $this->attachments;

        // Queue the agent response, on callback deliver the reply via email
        resolve(ChatBotAgent::class, ['message' => $incomingMessage, 'thread' => $thread])
            ->queue(...$incomingMessage->toAgentInput())
            ->then(function (AgentResponse $response) use ($thread, $connector, $incomingMessage, $attachments): void {
                $thread->update(['conversation_id' => $response->conversationId]);

                $connector->reply(
                    thread: $thread,
                    text: $response->text,
                    attachments: $attachments->outbound($incomingMessage->uuid)->getAll(),
                );
            })
            ->catch(function (Throwable $e): void {
                Log::error('Email agent error', ['error' => $e->getMessage()]);
            });
    }
}
