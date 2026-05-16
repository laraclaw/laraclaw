<?php

use Laraclaw\Models\Embedding;

beforeEach(function (): void {
    $this->requireEnv('ANTHROPIC_API_KEY', 'OPENAI_API_KEY');
    $this->authenticatedUser();
});

it('embeds a saved fact and recalls it in a fresh conversation', function (): void {
    $save = $this->postMessage('Remember this for later: my favourite colour is teal.');

    expect($save['success'])->toBeTrue();
    expect(Embedding::count())->toBeGreaterThanOrEqual(2);

    // Start a fresh conversation by sending without a key.
    $recall = $this->postMessage('What is my favourite colour?');

    expect($recall['success'])->toBeTrue();
    expect(strtolower($recall['text']))->toContain('teal');
});
