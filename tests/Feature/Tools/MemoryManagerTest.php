<?php

use Laraclaw\Models\Embedding;
use Laraclaw\Tools\MemoryManager;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Tools\Request;

beforeEach(function () {
    $this->user = $this->createUser();
    config(['laraclaw.auth.admin_user_id' => $this->user->id]);

    Embeddings::fake();
});

it('tells the agent when to reach for memory, not just what it does', function () {
    // A bland description left the agent answering "not in this chat" from context
    // alone instead of searching.
    expect((new MemoryManager)->description())
        ->toContain('Search long term memory')
        ->toContain('outside the current chat')
        ->toContain('before telling the user you do not know');
});

it('returns matching embeddings for the current user', function () {
    $vector = Embeddings::fakeEmbedding(8);

    foreach (['Laravel tip one', 'Laravel tip two'] as $text) {
        Embedding::create([
            'user_id' => $this->user->id,
            'source_type' => 'message',
            'content' => $text,
            'content_hash' => hash('xxh128', $text),
            'embedding' => $vector,
        ]);
    }

    Embeddings::fake([fn () => [$vector]]);

    $result = (new MemoryManager)->handle(new Request(['query' => 'laravel']));

    expect($result)->toContain('Laravel tip one')->toContain('Laravel tip two');
});

it('returns nothing for embeddings owned by another user', function () {
    $otherUser = $this->createUser();
    $vector = Embeddings::fakeEmbedding(8);

    Embedding::create([
        'user_id' => $otherUser->id,
        'source_type' => 'message',
        'content' => 'Other user secret',
        'content_hash' => hash('xxh128', 'Other user secret'),
        'embedding' => $vector,
    ]);

    Embeddings::fake([fn () => [$vector]]);

    $result = (new MemoryManager)->handle(new Request(['query' => 'secret']));

    expect($result)->not->toContain('Other user secret');
});
