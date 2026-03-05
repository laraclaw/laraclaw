<?php

namespace LaraClaw\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use LaraClaw\Agents\ChatBotAgent;
use LaraClaw\Channels\Contracts\SupportsAcknowledgement;
use LaraClaw\Message;
use Throwable;

/**
 * Takes a message off the queue, passes it through the AI agent and sends the response.
 */
class ProcessMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(
        private Message $message,
    ) {}

    /**
     * Acknowledge receipt, run the agent, and deliver the response via the channel.
     */
    public function handle(): void
    {
        $channel = $this->message->channel;

        if ($channel instanceof SupportsAcknowledgement) {
            $channel->acknowledge();
        }

        $agent = app(ChatBotAgent::class, ['message' => $this->message]);

        if (! $agent->isReady()) {
            return;
        }

        $response = $agent->send();

        if (blank($response)) {
            return;
        }

        $channel->handleAttachments($agent->replyAttachments);
        $channel->send($response);
    }

    /**
     * Log what went wrong and let the user know something failed via their channel.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('ProcessMessage failed', [
            'channel' => $this->message->channel->name,
            'key' => $this->message->conversationKey,
            'error' => $exception->getMessage(),
        ]);

        $this->message->channel->send('Sorry, something went wrong processing your message. Please try again.');
    }
}
