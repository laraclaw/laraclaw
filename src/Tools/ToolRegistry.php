<?php

namespace LaraClaw\Tools;

use Closure;
use Illuminate\Support\Collection;
use LaraClaw\Message;
use LaraClaw\Models\Conversation;
use Laravel\Ai\Contracts\Tool;

/**
 * Holds tool factories registered by the consumer and resolves them when the agent runs.
 */
class ToolRegistry
{
    /** @var Closure[] */
    private array $factories = [];

    /**
     * Register a factory closure that will be called with runtime context to produce a Tool.
     *
     * @param  Closure(Message, Collection, ?Conversation): Tool  $factory
     */
    public function register(Closure $factory): void
    {
        $this->factories[] = $factory;
    }

    /**
     * Invoke every registered factory with the given runtime context and return
     * the resulting tool instances.
     *
     * @return Tool[]
     */
    public function resolve(Message $message, Collection $attachments, ?Conversation $conversation): array
    {
        return collect($this->factories)
            ->map(fn (Closure $factory) => $factory($message, $attachments, $conversation))
            ->all();
    }
}
