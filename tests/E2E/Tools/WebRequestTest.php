<?php

beforeEach(function (): void {
    $this->requireEnv('ANTHROPIC_API_KEY');
    $this->authenticatedUser();
});

it('fetches an HTML page and reports a page title', function (): void {
    // example.com's <title> is "Example Domain". Asserting on the full phrase
    // rules out the model echoing "example" from the prompt without fetching.
    $reply = $this->postMessage('Fetch https://example.com and reply with the exact text of the <title> tag, nothing else.');

    expect($reply['success'])->toBeTrue();
    expect(strtolower($reply['text']))->toContain('example domain');
});

it('parses a JSON endpoint', function (): void {
    // Asking for stargazers_count forces the tool to actually fetch and parse
    // JSON; the value is an integer that doesn't appear anywhere in the prompt.
    $reply = $this->postMessage(
        'Fetch https://api.github.com/repos/laravel/laravel and reply with the integer value of the "stargazers_count" field. Nothing else.'
    );

    expect($reply['success'])->toBeTrue();
    expect($reply['text'])->toMatch('/\b\d{4,}\b/');
});
