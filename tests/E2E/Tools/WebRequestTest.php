<?php

beforeEach(function (): void {
    $this->requireEnv('ANTHROPIC_API_KEY');
    $this->authenticatedUser();
});

it('fetches an HTML page and reports a page title', function (): void {
    $reply = $this->postMessage('Fetch https://example.com and reply with the page title only.');

    expect($reply['success'])->toBeTrue();
    expect(strtolower($reply['text']))->toContain('example');
});

it('parses a JSON endpoint', function (): void {
    $reply = $this->postMessage(
        'Fetch https://api.github.com/repos/laravel/laravel and reply with the value of the "full_name" field only.'
    );

    expect($reply['success'])->toBeTrue();
    expect($reply['text'])->toContain('laravel/laravel');
});
