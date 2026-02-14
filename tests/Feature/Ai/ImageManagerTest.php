<?php

use App\Ai\Tools\ImageManager;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Tools\Request;

beforeEach(function () {
    Storage::fake('test-disk');
    Config::set('laraclaw.disk_manager.allowed_disks', ['test-disk']);
});

function im(array $params): string
{
    return (string) (new ImageManager)->handle(new Request($params));
}

function putTestImage(string $path = 'photo.png', int $width = 100, int $height = 80): void
{
    $img = imagecreatetruecolor($width, $height);
    $red = imagecolorallocate($img, 255, 0, 0);
    imagefill($img, 0, 0, $red);

    ob_start();
    imagepng($img);
    $data = ob_get_clean();
    imagedestroy($img);

    Storage::disk('test-disk')->put($path, $data);
}

// — info —

it('returns image dimensions, mime, and size', function () {
    putTestImage('photo.png', 200, 150);

    $result = im(['operation' => 'info', 'disk' => 'test-disk', 'path' => 'photo.png']);
    $info = json_decode($result, true);

    expect($info['width'])->toBe(200)
        ->and($info['height'])->toBe(150)
        ->and($info['size'])->toBeGreaterThan(0);
});

// — resize —

it('resizes an image by width', function () {
    putTestImage('photo.png', 200, 100);

    $result = im(['operation' => 'resize', 'disk' => 'test-disk', 'path' => 'photo.png', 'width' => 50]);

    expect($result)->toContain('Resized')
        ->and($result)->toContain('50x25');
});

it('resizes an image by height', function () {
    putTestImage('photo.png', 200, 100);

    $result = im(['operation' => 'resize', 'disk' => 'test-disk', 'path' => 'photo.png', 'height' => 50]);

    expect($result)->toContain('Resized')
        ->and($result)->toContain('100x50');
});

it('requires width or height for resize', function () {
    putTestImage();

    $result = im(['operation' => 'resize', 'disk' => 'test-disk', 'path' => 'photo.png']);

    expect($result)->toContain('"width" or "height" is required');
});

// — crop —

it('crops an image to specified dimensions', function () {
    putTestImage('photo.png', 200, 150);

    $result = im(['operation' => 'crop', 'disk' => 'test-disk', 'path' => 'photo.png', 'width' => 50, 'height' => 50]);

    expect($result)->toContain('Cropped');

    $info = json_decode(im(['operation' => 'info', 'disk' => 'test-disk', 'path' => 'photo.png']), true);
    expect($info['width'])->toBe(50)
        ->and($info['height'])->toBe(50);
});

it('requires both dimensions for crop', function () {
    putTestImage();

    $result = im(['operation' => 'crop', 'disk' => 'test-disk', 'path' => 'photo.png', 'width' => 50]);

    expect($result)->toContain('"width" and "height" are required');
});

// — orient —

it('rotates an image 90 degrees', function () {
    putTestImage('photo.png', 200, 100);

    $result = im(['operation' => 'orient', 'disk' => 'test-disk', 'path' => 'photo.png', 'orientation' => 'rotate_90']);

    expect($result)->toContain('Applied rotate_90');

    $info = json_decode(im(['operation' => 'info', 'disk' => 'test-disk', 'path' => 'photo.png']), true);
    expect($info['width'])->toBe(100)
        ->and($info['height'])->toBe(200);
});

it('flips an image horizontally', function () {
    putTestImage();

    $result = im(['operation' => 'orient', 'disk' => 'test-disk', 'path' => 'photo.png', 'orientation' => 'flip_horizontal']);

    expect($result)->toContain('Applied flip_horizontal');
});

it('requires orientation parameter', function () {
    putTestImage();

    $result = im(['operation' => 'orient', 'disk' => 'test-disk', 'path' => 'photo.png']);

    expect($result)->toContain('"orientation" parameter is required');
});

it('rejects unknown orientation', function () {
    putTestImage();

    $result = im(['operation' => 'orient', 'disk' => 'test-disk', 'path' => 'photo.png', 'orientation' => 'spin']);

    expect($result)->toContain('Unknown orientation');
});

// — convert —

it('converts an image to a different format', function () {
    putTestImage('photo.png', 100, 80);

    $result = im(['operation' => 'convert', 'disk' => 'test-disk', 'path' => 'photo.png', 'format' => 'jpg']);

    expect($result)->toContain('Converted')
        ->and($result)->toContain('photo.jpg')
        ->and(Storage::disk('test-disk')->exists('photo.jpg'))->toBeTrue();
});

it('requires a valid format for convert', function () {
    putTestImage();

    $result = im(['operation' => 'convert', 'disk' => 'test-disk', 'path' => 'photo.png']);

    expect($result)->toContain('"format" parameter is required');
});

// — optimize —

it('optimizes an image with quality', function () {
    putTestImage('photo.png', 200, 150);

    $result = im(['operation' => 'optimize', 'disk' => 'test-disk', 'path' => 'photo.png', 'quality' => 50]);

    expect($result)->toContain('Optimized')
        ->and($result)->toContain('bytes');
});

// — Security / validation —

it('rejects unauthorized disks', function () {
    $result = im(['operation' => 'info', 'disk' => 'secret', 'path' => 'photo.png']);

    expect($result)->toContain('not allowed');
});

it('rejects path traversal', function () {
    $result = im(['operation' => 'info', 'disk' => 'test-disk', 'path' => '../etc/passwd.png']);

    expect($result)->toContain('Path traversal is not allowed');
});

it('returns error for missing file', function () {
    $result = im(['operation' => 'info', 'disk' => 'test-disk', 'path' => 'nope.png']);

    expect($result)->toContain('File not found');
});

it('rejects non-image files', function () {
    Storage::disk('test-disk')->put('readme.txt', 'hello');

    $result = im(['operation' => 'info', 'disk' => 'test-disk', 'path' => 'readme.txt']);

    expect($result)->toContain('Not an image file');
});

it('rejects unknown operations', function () {
    putTestImage();

    $result = im(['operation' => 'blur', 'disk' => 'test-disk', 'path' => 'photo.png']);

    expect($result)->toContain('Unknown operation');
});
