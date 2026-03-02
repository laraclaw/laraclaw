<?php

namespace LaraClaw\Tests\Fixtures;

use Illuminate\Support\Str;
use Laravel\Ai\Contracts\ConversationStore;

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

    public function storeConversation(mixed $userId, string $name): string
    {
        $id = (string) Str::uuid();
        $this->conversations[$userId] = $id;

        return $id;
    }
}
