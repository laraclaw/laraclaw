<?php

namespace LaraClaw\Listeners;

use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use LaraClaw\Agents\ChatBotAgent;
use LaraClaw\Channels\TelegramChannel;
use LaraClaw\Commands\CommandRegistry;
use LaraClaw\Events\TelegramMessageReceived;
use LaraClaw\Models\Thread;
use LaraClaw\Services\Attachments;
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
            TelegramChannel::validateEvent($raw);
        } catch (ValidationException $e) {
            Log::debug('Telegram event skipped', ['code' => $e->getMessage()]);

            return;
        }

        $channel = new TelegramChannel($raw->getChat()->getId(), $event->bot);
        $incomingMessage = TelegramChannel::createIncomingMessageFrom($raw, $event->bot, $this->attachments);
        $thread = Thread::forMessage($incomingMessage);

        // Check if the message is a reply to a pending confirmation
        if ($channel->resolvePendingConfirmation($incomingMessage)) {
            return;
        }

        // Show a typing indicator to acknowledge the incoming message
        $channel->showTypingIndicator();

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

                $thread->channel()->reply(
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
