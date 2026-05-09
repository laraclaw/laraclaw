<?php

namespace Laraclaw\Listeners;

use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laraclaw\Agents\ChatBotAgent;
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
    public function __construct(
        private readonly Attachments $attachments,
        private readonly CommandRegistry $commands,
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

        // Check if the message is a reply to a pending confirmation
        if ($connector->resolvePendingConfirmation($incomingMessage)) {
            return;
        }

        // Show a typing indicator to acknowledge the incoming message
        $connector->showTypingIndicator();

        // Handle commands
        if ($command = $this->commands->match($incomingMessage->text ?? '')) {
            $command->handle($incomingMessage, $thread);

            return;
        }

        // We need a reference to pass to the callback
        $attachments = $this->attachments;

        // Queue the agent response, on callback deliver the reply via Telegram
        resolve(ChatBotAgent::class, ['message' => $incomingMessage, 'thread' => $thread])
            ->queue(...$incomingMessage->toAgentInput())
            ->then(function (AgentResponse $response) use ($thread, $incomingMessage, $attachments): void {
                $thread->update(['conversation_id' => $response->conversationId]);

                $thread->connector()->reply(
                    thread: $thread,
                    text: $response->text,
                    attachments: $attachments->outbound($incomingMessage->uuid)->getAll(),
                );
            })
            ->catch(function (Throwable $e): void {
                Log::error('Telegram agent error', ['error' => $e->getMessage()]);
            });
    }
}
