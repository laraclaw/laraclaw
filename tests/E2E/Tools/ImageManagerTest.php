<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function (): void {
    $this->requireEnv('ANTHROPIC_API_KEY');
    $this->authenticatedUser();
});

it('resizes an attached image and returns it as an outbound attachment', function (): void {
    if (! function_exists('imagecreatetruecolor')) {
        $this->markTestSkipped('GD extension not available.');
    }

    $img = imagecreatetruecolor(800, 600);
    imagefill($img, 0, 0, imagecolorallocate($img, 30, 120, 200));
    $tmp = tempnam(sys_get_temp_dir(), 'laraclaw-e2e-');
    imagejpeg($img, $tmp, 90);
    imagedestroy($img);

    $upload = new UploadedFile($tmp, 'input.jpg', 'image/jpeg', null, true);

    $response = $this->postJson('/api/message', [
        'text' => 'Use ImageManager to resize the attached image to 200x200 and send the resized version back.',
        'attachments' => [$upload],
    ], $this->apiHeaders());

    $response->assertOk();
    $reply = $response->json();

    expect($reply['success'])->toBeTrue();
    expect($reply['attachments'])->not->toBeEmpty();

    $attachment = $reply['attachments'][0];
    expect($attachment['mime_type'])->toBe('image/jpeg');
    expect(Storage::disk('local')->exists($attachment['path']))->toBeTrue();

    $bytes = Storage::disk('local')->get($attachment['path']);
    [$width, $height] = getimagesizefromstring($bytes);

    // Either dimension at 200 satisfies the request when aspect ratio is preserved.
    expect(min($width, $height))->toBeLessThanOrEqual(200);
    expect($width)->toBeLessThan(800);
});
