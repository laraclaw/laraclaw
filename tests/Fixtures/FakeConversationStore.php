<?php

namespace LaraClaw\Tests\Fixtures;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;

/**
 * In-memory ConversationStore stub. Stores conversation IDs per user and returns
 * the last stored ID on lookup, or null if none has been stored yet.
 */
class FakeConversationStore implements ConversationStore
{
    private array $conversations = [];

    public function latestConversationId(mixed $userId): ?string
    {
        return $this->conversations[$userId] ?? null;
    }

    public function storeConversation(string|int|null $userId, string $title): string
    {
        $id = (string) Str::uuid();
        $this->conversations[$userId] = $id;

        return $id;
    }

    public function storeUserMessage(string $conversationId, string|int|null $userId, AgentPrompt $prompt): string
    {
        return (string) Str::uuid();
    }

    public function storeAssistantMessage(string $conversationId, string|int|null $userId, AgentPrompt $prompt, AgentResponse $response): string
    {
        return (string) Str::uuid();
    }

    public function getLatestConversationMessages(string $conversationId, int $limit): Collection
    {
        return collect();
    }
}
