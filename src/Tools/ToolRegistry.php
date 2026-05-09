<?php

namespace Laraclaw\Tools;

use Closure;
use Laraclaw\DTOs\IncomingMessage;
use Laraclaw\Models\Thread;
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
     * @param  Closure(IncomingMessage, ?Thread): Tool  $factory
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
    public function resolve(IncomingMessage $message, ?Thread $thread): array
    {
        return collect($this->factories)
            ->map(fn (Closure $factory) => $factory($message, $thread))
            ->all();
    }
}
