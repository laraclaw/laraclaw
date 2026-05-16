<?php

beforeEach(function (): void {
    $this->requireEnv('ANTHROPIC_API_KEY');
    $this->authenticatedUser();
});

it('switches to a named persona and replies in character', function (): void {
    $reply = $this->postMessage(
        'Use the Persona tool to switch to a pirate persona, then greet me in two short sentences.'
    );

    expect($reply['success'])->toBeTrue();

    $text = strtolower($reply['text']);
    expect($text)->toMatch('/(arr|ahoy|matey|ye|aye|landlubber)/');
});
