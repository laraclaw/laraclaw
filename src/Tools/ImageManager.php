<?php

namespace LaraClaw\Tools;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use LaraClaw\DTOs\Attachment;
use LaraClaw\Message;
use Laravel\Ai\Tools\Request;
use RuntimeException;
use Spatie\Image\Enums\FlipDirection;
use Spatie\Image\Enums\ImageDriver;
use Spatie\Image\Enums\Orientation;
use Spatie\Image\Image;
use Stringable;

/**
 * Agent tool for reading image metadata and performing image transformations on disk.
 */
class ImageManager extends BaseTool
{
    public function __construct(protected Message $message, private ?Collection $attachments = null) {}

    public function description(): Stringable|string
    {
        $disks = implode(', ', config('laraclaw.filesystem.allowed_disks', []));

        return "Work with images: get info, resize, crop, orient, convert, optimize. Allowed disks: {$disks}. Operations: " . implode(', ', $this->operations()) . '. After any write operation (resize, crop, orient, convert, optimize) the resulting image is automatically sent to the user — do NOT say you cannot send files.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'operation' => $schema->string()->required()->description('The operation to perform: ' . implode(', ', $this->operations())),
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
        if ($error = $this->validateDiskAccess($request['disk'], $request['path'])) {
            return $error;
        }

        $operation = $request['operation'];

        if (! in_array($operation, $this->operations(), true)) {
            return "Unknown operation '{$operation}'. Available: " . implode(', ', $this->operations());
        }

        $storage = $this->storage($request);
        $path = $request['path'];

        if (! $storage->exists($path)) {
            return "File not found: {$path}";
        }

        if (! str_starts_with($storage->mimeType($path) ?: '', 'image/')) {
            return "Not an image file: {$path}";
        }

        $suffix = match ($operation) {
            'resize' => '_resized',
            'crop' => '_cropped',
            'orient' => '_' . ($request['orientation'] ?? 'oriented'),
            'optimize' => '_optimized',
            default => '',
        };

        $targetPath = $operation !== 'info' && $operation !== 'convert'
            ? $this->suffixedPath($path, $suffix)
            : $path;

        $result = match ($operation) {
            'info' => $this->info($storage, $path),
            'resize' => $this->resize($storage, $path, $targetPath, ($request['width'] ?? null) ?: null, ($request['height'] ?? null) ?: null),
            'crop' => $this->crop($storage, $path, $targetPath, ($request['width'] ?? null) ?: null, ($request['height'] ?? null) ?: null),
            'orient' => $this->orient($storage, $path, $targetPath, $request['orientation'] ?? null),
            'convert' => $this->convert($storage, $path, $request['format'] ?? null),
            'optimize' => $this->optimize($storage, $path, $targetPath, ($request['quality'] ?? null) ?: null),
        };

        if ($operation !== 'info') {
            $dir = dirname($path) === '.' ? '' : dirname($path) . '/';
            $pendingPath = $operation === 'convert'
                ? $dir . pathinfo($path, PATHINFO_FILENAME) . '.' . ($request['format'] ?? '')
                : $targetPath;
            $this->setPending($request['disk'], $pendingPath);
        }

        return $result;
    }

    /** @return string[] */
    protected function operations(): array
    {
        return ['info', 'resize', 'crop', 'orient', 'convert', 'optimize'];
    }

    /**
     * Return width, height, MIME type, and file size for an image.
     */
    protected function info(Filesystem $storage, string $path): string
    {
        $tempPath = $this->toTempFile($storage, $path);

        try {
            $image = Image::useImageDriver($this->driver())->loadFile($tempPath);

            return json_encode([
                'width' => $image->getWidth(),
                'height' => $image->getHeight(),
                'mime' => $storage->mimeType($path),
                'size' => $storage->size($path),
            ], JSON_PRETTY_PRINT);
        } finally {
            $this->cleanupTempFile($tempPath);
        }
    }

    /**
     * Resize an image to the given width and/or height (aspect ratio preserved for single-dimension).
     */
    protected function resize(Filesystem $storage, string $path, string $targetPath, ?int $width, ?int $height): string
    {
        if ($width === null && $height === null) {
            return 'At least one of "width" or "height" is required for resize.';
        }

        $tempPath = $this->toTempFile($storage, $path);

        try {
            $image = Image::useImageDriver($this->driver())->loadFile($tempPath);

            if ($width !== null) {
                $image->width($width);
            }
            if ($height !== null) {
                $image->height($height);
            }

            $image->quality(100)->save();
            $this->fromTempFile($storage, $targetPath, $tempPath);

            $image = Image::useImageDriver($this->driver())->loadFile($tempPath);

            return "Resized to {$image->getWidth()}x{$image->getHeight()}, saved as {$targetPath}.";
        } finally {
            $this->cleanupTempFile($tempPath);
        }
    }

    /**
     * Crop an image to exact dimensions from the center.
     */
    protected function crop(Filesystem $storage, string $path, string $targetPath, ?int $width, ?int $height): string
    {
        if ($width === null || $height === null) {
            return 'Both "width" and "height" are required for crop.';
        }

        $tempPath = $this->toTempFile($storage, $path);

        try {
            Image::useImageDriver($this->driver())->loadFile($tempPath)->crop($width, $height)->quality(100)->save();
            $this->fromTempFile($storage, $targetPath, $tempPath);

            return "Cropped to {$width}x{$height}, saved as {$targetPath}.";
        } finally {
            $this->cleanupTempFile($tempPath);
        }
    }

    /**
     * Rotate or flip an image using one of the supported orientation values.
     */
    protected function orient(Filesystem $storage, string $path, string $targetPath, ?string $orientation): string
    {
        if ($orientation === null) {
            return 'The "orientation" parameter is required for orient.';
        }

        $valid = ['rotate_90', 'rotate_180', 'rotate_270', 'flip_horizontal', 'flip_vertical'];

        if (! in_array($orientation, $valid, true)) {
            return "Unknown orientation '{$orientation}'. Use: " . implode(', ', $valid) . '.';
        }

        $tempPath = $this->toTempFile($storage, $path);

        try {
            $image = Image::useImageDriver($this->driver())->loadFile($tempPath);

            match ($orientation) {
                'rotate_90' => $image->orientation(Orientation::Rotate90),
                'rotate_180' => $image->orientation(Orientation::Rotate180),
                'rotate_270' => $image->orientation(Orientation::Rotate270),
                'flip_horizontal' => $image->flip(FlipDirection::Horizontal),
                'flip_vertical' => $image->flip(FlipDirection::Vertical),
            };

            $image->quality(100)->save();
            $this->fromTempFile($storage, $targetPath, $tempPath);

            return "Applied {$orientation}, saved as {$targetPath}.";
        } finally {
            $this->cleanupTempFile($tempPath);
        }
    }

    /**
     * Convert an image to a different format (jpg, png, or webp).
     */
    protected function convert(Filesystem $storage, string $path, ?string $format): string
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
            Image::useImageDriver($this->driver())->loadFile($tempPath)->format($format)->quality(100)->save($tempOut);

            $storage->put($newPath, file_get_contents($tempOut));

            return "Converted {$path} to {$newPath}.";
        } finally {
            $this->cleanupTempFile($tempPath);
            $this->cleanupTempFile($tempOut);
        }
    }

    /**
     * Re-save an image at the specified quality level to reduce file size.
     */
    protected function optimize(Filesystem $storage, string $path, string $targetPath, ?int $quality): string
    {
        $tempPath = $this->toTempFile($storage, $path);

        try {
            $image = Image::useImageDriver($this->driver())->loadFile($tempPath);

            $image->quality($quality !== null ? max(1, min(100, $quality)) : 100)->save();
            $this->fromTempFile($storage, $targetPath, $tempPath);

            $newSize = $storage->size($targetPath);

            return "Optimized, saved as {$targetPath}. New size: {$newSize} bytes.";
        } finally {
            $this->cleanupTempFile($tempPath);
        }
    }

    /**
     * Resolve the configured Spatie image driver (imagick or gd).
     */
    private function driver(): ImageDriver
    {
        $driver = config('laraclaw.tools.image_manager.driver', 'imagick');
        $imageDriver = match ($driver) {
            'gd' => ImageDriver::Gd,
            default => ImageDriver::Imagick,
        };

        return $imageDriver;
    }

    /**
     * Insert a suffix before the file extension in a path (e.g. "img.jpg" → "img_resized.jpg").
     */
    private function suffixedPath(string $path, string $suffix): string
    {
        $dir = dirname($path) === '.' ? '' : dirname($path) . '/';
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $name = pathinfo($path, PATHINFO_FILENAME);

        return $dir . $name . $suffix . ($ext !== '' ? '.' . $ext : '');
    }

    /**
     * Add a processed image to the pending reply attachments collection.
     */
    private function setPending(string $disk, string $path): void
    {
        $this->attachments?->push(new Attachment(path: $path, disk: $disk));
    }

    /**
     * Copy a storage file to a local temp path so Spatie Image can process it.
     */
    private function toTempFile(Filesystem $storage, string $path): string
    {
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $tempPath = sys_get_temp_dir() . '/' . uniqid('imgmgr_') . '.' . $ext;
        $written = file_put_contents($tempPath, $storage->get($path));

        if ($written === false) {
            throw new RuntimeException("Failed to write temp file: {$tempPath}");
        }

        return $tempPath;
    }

    /**
     * Write the processed temp file back to the storage disk.
     */
    private function fromTempFile(Filesystem $storage, string $path, string $tempPath): void
    {
        $storage->put($path, file_get_contents($tempPath));
    }

    /**
     * Delete a local temp file if it exists.
     */
    private function cleanupTempFile(string $path): void
    {
        if (file_exists($path)) {
            unlink($path);
        }
    }
}
