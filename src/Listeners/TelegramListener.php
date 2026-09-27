<?php

namespace Laraclaw\Listeners;

use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laraclaw\Agents\Dispatcher;
use Laraclaw\Connectors\Telegram;
use Laraclaw\Events\TelegramMessageReceived;
use Laraclaw\Models\Thread;
use Laraclaw\Services\Attachments;

/**
 * Handles incoming Telegram messages, validates them and queues the agent.
 */
class TelegramListener
{
    /**
     * Inject the attachment writer and the dispatcher that queues the agent.
     */
    public function __construct(
        private readonly Attachments $attachments,
        private readonly Dispatcher $dispatcher,
    ) {}

    /**
     * Validate the message, build the incoming message, and queue the agent for a reply.
     */
    public function __invoke(TelegramMessageReceived $event): void
    {
        try {
            Telegram::validateEvent($event->message);
        } catch (ValidationException $e) {
            Log::debug('Telegram event skipped', ['code' => $e->getMessage()]);

            return;
        }

        $message = Telegram::createIncomingMessageFrom($event->message, $event->bot, $this->attachments);

        new Telegram($event->message->getChat()->getId(), $event->bot)->showTypingIndicator();

        $this->dispatcher->queue($message, Thread::forMessage($message));
    }
}
