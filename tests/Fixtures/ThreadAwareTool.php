<?php

namespace Laraclaw\Tests\Fixtures;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laraclaw\DTOs\IncomingMessage;
use Laraclaw\Models\Thread;
use Laraclaw\Tools\BaseTool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * A tool that asks for the thread as well as the message.
 */
class ThreadAwareTool extends BaseTool
{
    public function __construct(protected IncomingMessage $message, public readonly ?Thread $thread = null) {}

    public function description(): Stringable|string
    {
        return 'Knows which thread it is in.';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['operation' => $schema->string()->required()->description('Operation: whereami')];
    }

    protected function operations(): array
    {
        return ['whereami'];
    }

    protected function whereami(Request $request): string
    {
        return (string) $this->thread?->key;
    }
}
