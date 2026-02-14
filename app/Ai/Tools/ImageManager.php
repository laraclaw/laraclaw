<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Spatie\Image\Enums\FlipDirection;
use Spatie\Image\Enums\ImageDriver;
use Spatie\Image\Enums\Orientation;
use Spatie\Image\Image;
use Stringable;

class ImageManager implements Tool
{
    private const OPERATIONS = ['info', 'resize', 'crop', 'orient', 'convert', 'optimize'];

    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff', 'tif'];

    public function description(): Stringable|string
    {
        $disks = implode(', ', config('laraclaw.disk_manager.allowed_disks', []));
        $ops = implode(', ', self::OPERATIONS);

        return "Work with images: get info, resize, crop, orient, convert, optimize. Allowed disks: {$disks}. Operations: {$ops}.";
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'operation' => $schema->string()->required()->description('The operation to perform: ' . implode(', ', self::OPERATIONS)),
            'disk' => $schema->string()->required()->description('The storage disk to use'),
            'path' => $schema->string()->required()->description('The image file path'),
            'width' => $schema->integer()->description('For resize/crop: target width in pixels'),
            'height' => $schema->integer()->description('For resize/crop: target height in pixels'),
            'format' => $schema->string()->description('For convert: target format (jpg, png, webp)'),
            'quality' => $schema->integer()->description('For optimize: quality 1-100'),
            'orientation' => $schema->string()->description('For orient: rotate_90, rotate_180, rotate_270, flip_horizontal, flip_vertical'),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $operation = $request['operation'];
        $disk = $request['disk'];
        $path = $request['path'];

        $allowedDisks = config('laraclaw.disk_manager.allowed_disks', []);

        if (! in_array($disk, $allowedDisks, true)) {
            return "Disk '{$disk}' is not allowed. Allowed disks: " . implode(', ', $allowedDisks);
        }

        if (str_contains($path, '..')) {
            return 'Path traversal is not allowed.';
        }

        if (! in_array($operation, self::OPERATIONS, true)) {
            return "Unknown operation '{$operation}'. Available: " . implode(', ', self::OPERATIONS);
        }

        $storage = Storage::disk($disk);

        if (! $storage->exists($path)) {
            return "File not found: {$path}";
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (! in_array($ext, self::IMAGE_EXTENSIONS, true)) {
            return "Not an image file: {$path}";
        }

        Log::info('ImageManager: executing', compact('operation', 'disk', 'path'));

        return match ($operation) {
            'info' => $this->info($storage, $path),
            'resize' => $this->resize($storage, $path, $request['width'] ?? null, $request['height'] ?? null),
            'crop' => $this->crop($storage, $path, $request['width'] ?? null, $request['height'] ?? null),
            'orient' => $this->orient($storage, $path, $request['orientation'] ?? null),
            'convert' => $this->convert($storage, $path, $request['format'] ?? null),
            'optimize' => $this->optimize($storage, $path, $request['quality'] ?? null),
        };
    }

    private function info(Filesystem $storage, string $path): string
    {
        $tempPath = $this->toTempFile($storage, $path);

        try {
            $image = Image::useImageDriver(ImageDriver::Gd)->loadFile($tempPath);

            return json_encode([
                'width' => $image->getWidth(),
                'height' => $image->getHeight(),
                'mime' => $storage->mimeType($path),
                'size' => $storage->size($path),
            ], JSON_PRETTY_PRINT);
        } finally {
            @unlink($tempPath);
        }
    }

    private function resize(Filesystem $storage, string $path, ?int $width, ?int $height): string
    {
        if ($width === null && $height === null) {
            return 'At least one of "width" or "height" is required for resize.';
        }

        $tempPath = $this->toTempFile($storage, $path);

        try {
            $image = Image::useImageDriver(ImageDriver::Gd)->loadFile($tempPath);

            if ($width !== null) {
                $image->width($width);
            }
            if ($height !== null) {
                $image->height($height);
            }

            $image->save();
            $this->fromTempFile($storage, $path, $tempPath);

            $image = Image::useImageDriver(ImageDriver::Gd)->loadFile($tempPath);

            return "Resized {$path} to {$image->getWidth()}x{$image->getHeight()}.";
        } finally {
            @unlink($tempPath);
        }
    }

    private function crop(Filesystem $storage, string $path, ?int $width, ?int $height): string
    {
        if ($width === null || $height === null) {
            return 'Both "width" and "height" are required for crop.';
        }

        $tempPath = $this->toTempFile($storage, $path);

        try {
            Image::useImageDriver(ImageDriver::Gd)->loadFile($tempPath)->crop($width, $height)->save();
            $this->fromTempFile($storage, $path, $tempPath);

            return "Cropped {$path} to {$width}x{$height}.";
        } finally {
            @unlink($tempPath);
        }
    }

    private function orient(Filesystem $storage, string $path, ?string $orientation): string
    {
        if ($orientation === null) {
            return 'The "orientation" parameter is required for orient.';
        }

        $tempPath = $this->toTempFile($storage, $path);

        try {
            $image = Image::useImageDriver(ImageDriver::Gd)->loadFile($tempPath);

            match ($orientation) {
                'rotate_90' => $image->orientation(Orientation::Rotate90),
                'rotate_180' => $image->orientation(Orientation::Rotate180),
                'rotate_270' => $image->orientation(Orientation::Rotate270),
                'flip_horizontal' => $image->flip(FlipDirection::Horizontal),
                'flip_vertical' => $image->flip(FlipDirection::Vertical),
                default => null,
            };

            if (! in_array($orientation, ['rotate_90', 'rotate_180', 'rotate_270', 'flip_horizontal', 'flip_vertical'], true)) {
                return "Unknown orientation '{$orientation}'. Use: rotate_90, rotate_180, rotate_270, flip_horizontal, flip_vertical.";
            }

            $image->save();
            $this->fromTempFile($storage, $path, $tempPath);

            return "Applied {$orientation} to {$path}.";
        } finally {
            @unlink($tempPath);
        }
    }

    private function convert(Filesystem $storage, string $path, ?string $format): string
    {
        $allowedFormats = ['jpg', 'png', 'webp'];

        if ($format === null || ! in_array($format, $allowedFormats, true)) {
            return 'The "format" parameter is required for convert. Allowed: jpg, png, webp.';
        }

        $tempPath = $this->toTempFile($storage, $path);
        $newPath = pathinfo($path, PATHINFO_DIRNAME);
        $newPath = ($newPath === '.' ? '' : $newPath . '/') . pathinfo($path, PATHINFO_FILENAME) . '.' . $format;

        $tempOut = $tempPath . '.' . $format;

        try {
            Image::useImageDriver(ImageDriver::Gd)->loadFile($tempPath)->format($format)->save($tempOut);

            $storage->put($newPath, file_get_contents($tempOut));

            return "Converted {$path} to {$newPath}.";
        } finally {
            @unlink($tempPath);
            @unlink($tempOut);
        }
    }

    private function optimize(Filesystem $storage, string $path, ?int $quality): string
    {
        $tempPath = $this->toTempFile($storage, $path);

        try {
            $image = Image::useImageDriver(ImageDriver::Gd)->loadFile($tempPath);

            if ($quality !== null) {
                $image->quality(max(1, min(100, $quality)));
            }

            $image->save();
            $this->fromTempFile($storage, $path, $tempPath);

            $newSize = $storage->size($path);

            return "Optimized {$path}. New size: {$newSize} bytes.";
        } finally {
            @unlink($tempPath);
        }
    }

    private function toTempFile(Filesystem $storage, string $path): string
    {
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $tempPath = sys_get_temp_dir() . '/' . uniqid('imgmgr_') . '.' . $ext;
        file_put_contents($tempPath, $storage->get($path));

        return $tempPath;
    }

    private function fromTempFile(Filesystem $storage, string $path, string $tempPath): void
    {
        $storage->put($path, file_get_contents($tempPath));
    }
}
