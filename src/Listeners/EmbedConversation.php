<?php

namespace Laraclaw\Listeners;

use Laraclaw\Agents\ChatBotAgent;
use Laraclaw\Services\Memory\EmbedContent;
use Laravel\Ai\Events\AgentPrompted;

class EmbedConversation
{
    public function __construct(
        private readonly EmbedContent $embedContent,
    ) {}

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
