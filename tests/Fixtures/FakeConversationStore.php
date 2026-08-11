<?php

namespace Laraclaw\Tests\Fixtures;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;

/**
 * In-memory ConversationStore stub. Stores conversation IDs per participant and returns
 * the last stored ID on lookup, or null if none has been stored yet.
 */
class FakeConversationStore implements ConversationStore
{
    private array $conversations = [];

    public function latestConversationId(string $participantType, string|int $participantId): ?string
    {
        return $this->conversations[$this->key($participantType, $participantId)] ?? null;
    }

    public function storeConversation(?string $participantType, string|int|null $participantId, string $title): string
    {
        $id = (string) Str::uuid();
        $this->conversations[$this->key($participantType, $participantId)] = $id;

        return $id;
    }

    public function storeUserMessage(string $conversationId, ?string $participantType, string|int|null $participantId, AgentPrompt $prompt): string
    {
        return (string) Str::uuid();
    }

    public function storeAssistantMessage(string $conversationId, ?string $participantType, string|int|null $participantId, AgentPrompt $prompt, AgentResponse $response): ?string
    {
        return (string) Str::uuid();
    }

    public function getLatestConversationMessages(string $conversationId, int $limit): Collection
    {
        return collect();
    }

    public function storeApprovalResults(string $conversationId, ?string $participantType, string|int|null $participantId, array $toolResults): void
    {
        //
    }

    /**
     * Build the lookup key for a participant, tolerating a null type for unowned conversations.
     */
    private function key(?string $participantType, string|int|null $participantId): string
    {
        return $participantType . ':' . $participantId;
    }
}
