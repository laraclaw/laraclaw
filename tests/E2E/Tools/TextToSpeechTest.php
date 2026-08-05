<?php

use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->requireEnv('ANTHROPIC_API_KEY', 'OPENAI_API_KEY');
    $this->authenticatedUser();
});

it('generates a real mp3 attachment from text', function (): void {
    $reply = $this->postMessage(
        'Use TextToSpeech to say out loud the phrase: Laraclaw end-to-end test, ten four.'
    );

    expect($reply['success'])->toBeTrue();
    expect($reply['attachments'])->not->toBeEmpty();

    $attachment = $reply['attachments'][0];
    expect($attachment['mime_type'])->toBe('audio/mpeg');
    expect($attachment['filename'])->toBe('voice.mp3');

    $bytes = Storage::disk('local')->get($attachment['path']);
    expect(strlen($bytes))->toBeGreaterThan(1024);

    $header = substr($bytes, 0, 3);
    $isMp3 = $header === 'ID3' || in_array(substr($bytes, 0, 2), ["\xFF\xFB", "\xFF\xF3", "\xFF\xF2"], true);
    expect($isMp3)->toBeTrue();
});
