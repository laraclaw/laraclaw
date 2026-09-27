<?php

use Illuminate\Support\Facades\Storage;
use Laraclaw\DTOs\IncomingMessage;
use Laraclaw\Enums\ConnectorType;
use Laraclaw\Services\Attachments;
use Laraclaw\Tools\ImageManager;
use Laravel\Ai\Tools\Request;

function imageTool(): ImageManager
{
    return new ImageManager(new IncomingMessage(
        text: 'test',
        connector: ConnectorType::Terminal,
        key: 'user',
        isDirectMessage: true,
        uuid: 'msg-uuid',
    ), app(Attachments::class));
}

function imageRequest(array $data): Request
{
    return new Request(['disk' => 'workspace', 'path' => 'photo.png', ...$data], 'call_test');
}

beforeEach(function () {
    Storage::fake('workspace');
    Storage::fake('attachments');

    config([
        'laraclaw.filesystem.allowed_disks' => ['workspace'],
        'laraclaw.filesystem.attachments_disk' => 'attachments',
        'laraclaw.tools.image_manager.driver' => 'gd',
    ]);

    $canvas = imagecreatetruecolor(40, 20);
    ob_start();
    imagepng($canvas);
    Storage::disk('workspace')->put('photo.png', ob_get_clean());
    Storage::disk('workspace')->put('notes.txt', 'plain text');
});

it('reports the dimensions of an image', function () {
    $result = imageTool()->handle(imageRequest(['operation' => 'info']));

    expect(json_decode((string) $result, true))->toMatchArray(['width' => 40, 'height' => 20]);
});

it('refuses to work on a file that is not an image', function () {
    $result = imageTool()->handle(imageRequest(['operation' => 'info', 'path' => 'notes.txt']));

    expect((string) $result)->toContain('Not an image file');
});

it('resizes beside the original and queues the result for the reply', function () {
    $result = imageTool()->handle(imageRequest(['operation' => 'resize', 'width' => 20]));

    expect((string) $result)->toBe('Resized to 20x10, saved as photo_resized.png.')
        ->and(Storage::disk('workspace')->exists('photo_resized.png'))->toBeTrue()
        ->and(Storage::disk('workspace')->exists('photo.png'))->toBeTrue()
        ->and(app(Attachments::class)->outbound('msg-uuid')->getAll())->toHaveCount(1);
});

it('converts by writing the target extension', function () {
    $result = imageTool()->handle(imageRequest(['operation' => 'convert', 'format' => 'jpg']));

    expect((string) $result)->toBe('Converted photo.png, saved as photo.jpg.')
        ->and(Storage::disk('workspace')->mimeType('photo.jpg'))->toBe('image/jpeg');
});

it('needs at least one dimension to resize', function () {
    expect((string) imageTool()->handle(imageRequest(['operation' => 'resize'])))
        ->toContain('At least one of "width" or "height"');
});

it('rejects an orientation it does not know', function () {
    expect((string) imageTool()->handle(imageRequest(['operation' => 'orient', 'orientation' => 'sideways'])))
        ->toContain("Unknown orientation 'sideways'");
});
