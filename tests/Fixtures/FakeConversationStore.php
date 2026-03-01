<?php

namespace LaraClaw\Tests\Fixtures;

use Illuminate\Support\Str;
use Laravel\Ai\Contracts\ConversationStore;

/**
 * In-memory ConversationStore stub. Always returns null for lookups so tests
 * start with a fresh context, and hands back a UUID on store calls.
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
