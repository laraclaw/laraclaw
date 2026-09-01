<?php

namespace Laraclaw\Listeners;

use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laraclaw\Commands\CommandRegistry;
use Laraclaw\Connectors\Telegram;
use Laraclaw\Events\TelegramMessageReceived;
use Laraclaw\Jobs\RunAgentTurn;
use Laraclaw\Models\Thread;
use Laraclaw\Services\Attachments;

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

        // Hand the turn to the queue. The agent is built inside the job so it
        // reads the conversation the previous turn saved, and the job holds a
        // lock on the thread so two messages sent at once run one after the other.
        RunAgentTurn::dispatch($thread, $incomingMessage);
    }
}
