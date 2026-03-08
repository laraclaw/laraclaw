<?php

namespace LaraClaw\Listeners;

use DirectoryTree\ImapEngine\Laravel\Events\MessageReceived;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use LaraClaw\Agents\ChatBotAgent;
use LaraClaw\Channels\EmailChannel;
use LaraClaw\Commands\CommandRegistry;
use LaraClaw\Models\Thread;
use LaraClaw\Services\Attachments;
use Laravel\Ai\Responses\AgentResponse;
use Throwable;

use function LaraClaw\Support\logAgentUsage;

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
            EmailChannel::validateEvent($raw);
        } catch (ValidationException $e) {
            Log::debug('Email event skipped', ['code' => $e->getMessage()]);

            return;
        }

        $channel = EmailChannel::fromRawMessage($raw);
        $incomingMessage = EmailChannel::createIncomingMessageFrom($raw, $this->attachments);
        $thread = Thread::forMessage($incomingMessage);

        // Mark the email as seen so it is not reprocessed on the next poll
        EmailChannel::markSeen($raw->uid());

        // Check if the email is a reply to a pending confirmation
        if ($channel->resolvePendingConfirmation($incomingMessage)) {
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
            ->then(function (AgentResponse $response) use ($thread, $channel, $incomingMessage, $attachments): void {
                logAgentUsage('email', $response->usage);
                $thread->update(['conversation_id' => $response->conversationId]);

                $channel->reply(
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
