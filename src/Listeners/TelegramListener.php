<?php

namespace Laraclaw\Listeners;

use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laraclaw\Agents\ChatBotAgent;
use Laraclaw\Approvals\ApprovalFlow;
use Laraclaw\Commands\CommandRegistry;
use Laraclaw\Connectors\Telegram;
use Laraclaw\Events\TelegramMessageReceived;
use Laraclaw\Models\Thread;
use Laraclaw\Services\Attachments;
use Laravel\Ai\Responses\AgentResponse;
use Throwable;

/**
 * Handles incoming Telegram messages, validates them and queues the agent.
 */
class TelegramListener
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
     * Validate the message, build the incoming message, and queue the agent for a reply.
     */
    public function __invoke(TelegramMessageReceived $event): void
    {
        $raw = $event->message;

        // Validate the incoming Telegram message
        try {
            Telegram::validateEvent($raw);
        } catch (ValidationException $e) {
            Log::debug('Telegram event skipped', ['code' => $e->getMessage()]);

            return;
        }

        $connector = new Telegram($raw->getChat()->getId(), $event->bot);
        $incomingMessage = Telegram::createIncomingMessageFrom($raw, $event->bot, $this->attachments);
        $thread = Thread::forMessage($incomingMessage);

        // Show a typing indicator to acknowledge the incoming message
        $connector->showTypingIndicator();

        // Handle commands
        if ($command = $this->commands->match($incomingMessage->text ?? '')) {
            $command->handle($incomingMessage, $thread);

            return;
        }

        // We need references to pass to the callback
        $attachments = $this->attachments;
        $approvals = $this->approvals;

        $agent = resolve(ChatBotAgent::class, ['message' => $incomingMessage, 'thread' => $thread]);

        // When the agent paused on a gated tool call, this message is the user's
        // answer to it and resumes the paused run instead of starting a new turn.
        $decisions = $approvals->decisionsFrom($thread, $incomingMessage);

        // Queue the agent response, on callback deliver the reply via Telegram
        ($decisions ? $agent->queue($decisions) : $agent->queue(...$incomingMessage->toAgentInput()))
            ->then(function (AgentResponse $response) use ($thread, $incomingMessage, $attachments, $approvals): void {
                $thread->update(['conversation_id' => $response->conversationId]);

                $thread->connector()->reply(
                    thread: $thread,
                    text: $approvals->capture($thread, $response) ?? $response->text,
                    attachments: $attachments->outbound($incomingMessage->uuid)->getAll(),
                );
            })
            ->catch(function (Throwable $e) use ($thread, $approvals): void {
                // A stale resume leaves the thread pointing at a pause the conversation
                // no longer has. Clear it, or every later message reads as an answer.
                if ($notice = $approvals->recover($thread, $e)) {
                    $thread->connector()->reply($thread, $notice);

                    return;
                }

                Log::error('Telegram agent error', ['error' => $e->getMessage()]);
            });
    }
}
