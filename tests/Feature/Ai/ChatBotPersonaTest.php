<?php

use App\Ai\Agents\ChatBot;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;

it('prepends persona file content to instructions', function () {
    Storage::fake('laraclaw');
    Storage::disk('laraclaw')->put('personas/counselor.md', 'You are a wise counselor.');
    Config::set('laraclaw.persona', 'counselor');

    $instructions = (new ChatBot)->instructions();

    expect($instructions)
        ->toStartWith('You are a wise counselor.')
        ->toContain('You are Laraclaw');
});

it('returns default instructions when no persona is configured', function () {
    Storage::fake('laraclaw');
    Config::set('laraclaw.persona', null);

    $instructions = (new ChatBot)->instructions();

    expect($instructions)->toBe('You are Laraclaw, a helpful AI assistant. Answer the user\'s question concisely and accurately.');
});

it('returns default instructions when persona file is missing', function () {
    Storage::fake('laraclaw');
    Config::set('laraclaw.persona', 'nonexistent');

    $instructions = (new ChatBot)->instructions();

    expect($instructions)->toBe('You are Laraclaw, a helpful AI assistant. Answer the user\'s question concisely and accurately.');
});
