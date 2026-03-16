<?php

use LaraClaw\Enums\MemorySourceType;
use LaraClaw\Models\Embedding;
use Laravel\Ai\Embeddings;

beforeEach(function () {
    $this->user = $this->createUser();

    Embeddings::fake();
});

it('casts source_type to the MemorySourceType enum', function () {
    $embedding = Embedding::create([
        'user_id' => $this->user->id,
        'source_type' => 'message',
        'content' => 'Test content',
        'content_hash' => hash('xxh128', 'Test content'),
        'embedding' => Embeddings::fakeEmbedding(3),
    ]);

    expect($embedding->fresh()->source_type)->toBe(MemorySourceType::Message);
});

it('enforces unique user_id + content_hash', function () {
    $data = [
        'user_id' => $this->user->id,
        'source_type' => 'message',
        'content' => 'Duplicate test',
        'content_hash' => hash('xxh128', 'Duplicate test'),
        'embedding' => Embeddings::fakeEmbedding(3),
    ];

    Embedding::create($data);

    expect(fn () => Embedding::create($data))
        ->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});

it('searches similar embeddings with cosine similarity', function () {
    $dim = 8;
    $vector = Embeddings::fakeEmbedding($dim);

    // Seed with identical vectors so cosine similarity is 1.0 against any query
    foreach (['PHP is great', 'Laravel is a framework', 'Dogs are cute'] as $text) {
        Embedding::create([
            'user_id' => $this->user->id,
            'source_type' => 'message',
            'content' => $text,
            'content_hash' => hash('xxh128', $text),
            'embedding' => $vector,
        ]);
    }

    // Fake the query embedding to return the same vector
    Embeddings::fake([fn () => [$vector]]);

    $results = Embedding::searchSimilar(
        query: 'anything',
        userId: $this->user->id,
        limit: 3,
        minSimilarity: 0.0,
    );

    expect($results)->toHaveCount(3);
    expect($results[0])->toHaveKeys(['content', 'score', 'metadata']);
    expect($results[0]['score'])->toEqualWithDelta(1.0, 0.01);
});

it('does not return embeddings from other users', function () {
    $otherUser = $this->createUser();
    $vector = Embeddings::fakeEmbedding(8);

    Embedding::create([
        'user_id' => $otherUser->id,
        'source_type' => 'message',
        'content' => 'Other user content',
        'content_hash' => hash('xxh128', 'Other user content'),
        'embedding' => $vector,
    ]);

    Embeddings::fake([fn () => [$vector]]);

    $results = Embedding::searchSimilar(
        query: 'anything',
        userId: $this->user->id,
        limit: 10,
        minSimilarity: 0.0,
    );

    expect($results)->toBeEmpty();
});
