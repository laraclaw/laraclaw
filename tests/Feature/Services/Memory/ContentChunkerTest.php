<?php

use LaraClaw\Services\Memory\ContentChunker;

beforeEach(fn () => $this->chunker = new ContentChunker);

it('returns an empty array for empty input', function () {
    expect($this->chunker->chunk(''))->toBe([]);
    expect($this->chunker->chunk('   '))->toBe([]);
});

it('returns a single chunk for short content', function () {
    $text = 'Hello, world.';

    $chunks = $this->chunker->chunk($text);

    expect($chunks)->toHaveCount(1);
    expect($chunks[0])->toBe($text);
});

it('returns a single chunk for content exactly at the size limit', function () {
    $text = str_repeat('a', 1000);

    expect($this->chunker->chunk($text))->toHaveCount(1);
});

it('splits long content into overlapping chunks', function () {
    $text = str_repeat('word ', 300); // ~1500 chars

    $chunks = $this->chunker->chunk($text);

    expect(count($chunks))->toBeGreaterThan(1);

    // Chunks should overlap — the end of one should appear at the start of the next
    $overlapFound = str_contains($chunks[1], substr($chunks[0], -50));
    expect($overlapFound)->toBeTrue();
});

it('breaks at paragraph boundaries when possible', function () {
    $text = str_repeat('a', 500) . "\n\n" . str_repeat('b', 600);

    $chunks = $this->chunker->chunk($text);

    // First chunk should end before the paragraph break, not mid-word
    expect($chunks[0])->not->toContain('b');
});

it('breaks at sentence boundaries when no paragraph break is available', function () {
    $text = str_repeat('a', 500) . '. ' . str_repeat('b', 600);

    $chunks = $this->chunker->chunk($text);

    expect($chunks[0])->toEndWith('.');
});
