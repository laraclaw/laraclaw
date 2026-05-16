<?php

use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->requireEnv('ANTHROPIC_API_KEY');
    $this->authenticatedUser();

    $disk = Storage::disk('laraclaw_files');
    foreach ($disk->allFiles() as $file) {
        $disk->delete($file);
    }
    foreach ($disk->directories() as $dir) {
        $disk->deleteDirectory($dir);
    }
});

it('writes a text file then reads it back over two turns', function (): void {
    $write = $this->postMessage(
        'Use FileManager to write the text "Hello from the e2e suite." to the laraclaw_files disk at the path e2e-test.txt.'
    );

    expect($write['success'])->toBeTrue();
    expect(Storage::disk('laraclaw_files')->exists('e2e-test.txt'))->toBeTrue();
    expect(Storage::disk('laraclaw_files')->get('e2e-test.txt'))->toBe('Hello from the e2e suite.');

    $read = $this->postMessage(
        'Use FileManager to read e2e-test.txt from laraclaw_files and reply with the file contents only.',
        $write['key'],
    );

    expect($read['success'])->toBeTrue();
    expect($read['text'])->toContain('Hello from the e2e suite');
});
