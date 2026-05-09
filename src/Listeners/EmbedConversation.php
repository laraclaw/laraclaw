<?php

namespace Laraclaw\Listeners;

use Laraclaw\Agents\ChatBotAgent;
use Laraclaw\Services\Memory\EmbedContent;
use Laravel\Ai\Events\AgentPrompted;

class EmbedConversation
{
    /**
     * Inject the embedding pipeline that turns conversation chunks into vectors.
     */
    public function __construct(
        private readonly EmbedContent $embedContent,
    ) {}

    /**
     * After every ChatBotAgent turn, embed the message and the response into long-term memory.
     */
    public function __invoke(AgentPrompted $event): void
    {
        if (! config('laraclaw.memory.enabled')) {
            return;
        }

        if (! $event->prompt->agent instanceof ChatBotAgent) {
            return;
        }

        $agent = $event->prompt->agent;

        $this->embedContent->run(
            thread: $agent->thread,
            message: $agent->message,
            responseText: $event->response->text,
            conversationId: $event->response->conversationId,
        );
    }
}
