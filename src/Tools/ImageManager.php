<?php

namespace LaraClaw\Tools;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use LaraClaw\PendingImageReply;
use Laravel\Ai\Tools\Request;
use RuntimeException;
use Spatie\Image\Enums\FlipDirection;
use Spatie\Image\Enums\ImageDriver;
use Spatie\Image\Enums\Orientation;
use Spatie\Image\Image;
use Stringable;

class ImageManager extends BaseTool
{
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'tiff', 'tif'];

    private ?PendingImageReply $pendingImageReply;

    public function __construct(protected \LaraClaw\Channels\Channel $channel, ?PendingImageReply $pendingImageReply = null)
    {
        $this->pendingImageReply = $pendingImageReply;
    }

    protected function operations(): array
    {
        return ['info', 'resize', 'crop', 'orient', 'convert', 'optimize'];
    }

    public function description(): Stringable|string
    {
        $disks = implode(', ', config('laraclaw.tools.allowed_disks', []));

        return "Work with images: get info, resize, crop, orient, convert, optimize. Allowed disks: {$disks}. Operations: ".implode(', ', $this->operations()).'. After any write operation (resize, crop, orient, convert, optimize) the resulting image is automatically sent to the user — do NOT say you cannot send files.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'operation' => $schema->string()->required()->description('The operation to perform: '.implode(', ', $this->operations())),
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
            return "Unknown operation '{$operation}'. Available: ".implode(', ', $this->operations());
        }

        $storage = $this->storage($request);
        $path = $request['path'];

        if (! $storage->exists($path)) {
            return "File not found: {$path}";
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (! in_array($ext, self::IMAGE_EXTENSIONS, true)) {
            return "Not an image file: {$path}";
        }

        $suffix = match ($operation) {
            'resize' => '_resized',
            'crop' => '_cropped',
            'orient' => '_'.($request['orientation'] ?? 'oriented'),
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
            $dir = dirname($path) === '.' ? '' : dirname($path).'/';
            $pendingPath = $operation === 'convert'
                ? $dir.pathinfo($path, PATHINFO_FILENAME).'.'.($request['format'] ?? '')
                : $targetPath;
            $this->setPending($request['disk'], $pendingPath);
        }

        return $result;
    }

    protected function info(Filesystem $storage, string $path): string
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
            $this->cleanupTempFile($tempPath);
        }
    }

    private function suffixedPath(string $path, string $suffix): string
    {
        $dir = dirname($path) === '.' ? '' : dirname($path).'/';
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $name = pathinfo($path, PATHINFO_FILENAME);

        return $dir.$name.$suffix.($ext !== '' ? '.'.$ext : '');
    }

    private function setPending(string $disk, string $path): void
    {
        if ($this->pendingImageReply) {
            $this->pendingImageReply->disk = $disk;
            $this->pendingImageReply->path = $path;
        }
    }

    protected function resize(Filesystem $storage, string $path, string $targetPath, ?int $width, ?int $height): string
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

            $image->quality(100)->save();
            $this->fromTempFile($storage, $targetPath, $tempPath);

            $image = Image::useImageDriver(ImageDriver::Gd)->loadFile($tempPath);

            return "Resized to {$image->getWidth()}x{$image->getHeight()}, saved as {$targetPath}.";
        } finally {
            $this->cleanupTempFile($tempPath);
        }
    }

    protected function crop(Filesystem $storage, string $path, string $targetPath, ?int $width, ?int $height): string
    {
        if ($width === null || $height === null) {
            return 'Both "width" and "height" are required for crop.';
        }

        $tempPath = $this->toTempFile($storage, $path);

        try {
            Image::useImageDriver(ImageDriver::Gd)->loadFile($tempPath)->crop($width, $height)->quality(100)->save();
            $this->fromTempFile($storage, $targetPath, $tempPath);

            return "Cropped to {$width}x{$height}, saved as {$targetPath}.";
        } finally {
            $this->cleanupTempFile($tempPath);
        }
    }

    protected function orient(Filesystem $storage, string $path, string $targetPath, ?string $orientation): string
    {
        if ($orientation === null) {
            return 'The "orientation" parameter is required for orient.';
        }

        $valid = ['rotate_90', 'rotate_180', 'rotate_270', 'flip_horizontal', 'flip_vertical'];

        if (! in_array($orientation, $valid, true)) {
            return "Unknown orientation '{$orientation}'. Use: ".implode(', ', $valid).'.';
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
            };

            $image->quality(100)->save();
            $this->fromTempFile($storage, $targetPath, $tempPath);

            return "Applied {$orientation}, saved as {$targetPath}.";
        } finally {
            $this->cleanupTempFile($tempPath);
        }
    }

    protected function convert(Filesystem $storage, string $path, ?string $format): string
    {
        $allowedFormats = ['jpg', 'png', 'webp'];

        if ($format === null || ! in_array($format, $allowedFormats, true)) {
            return 'The "format" parameter is required for convert. Allowed: jpg, png, webp.';
        }

        $tempPath = $this->toTempFile($storage, $path);
        $newPath = pathinfo($path, PATHINFO_DIRNAME);
        $newPath = ($newPath === '.' ? '' : $newPath.'/').pathinfo($path, PATHINFO_FILENAME).'.'.$format;

        $tempOut = $tempPath.'.'.$format;

        try {
            Image::useImageDriver(ImageDriver::Gd)->loadFile($tempPath)->format($format)->quality(100)->save($tempOut);

            $storage->put($newPath, file_get_contents($tempOut));

            return "Converted {$path} to {$newPath}.";
        } finally {
            $this->cleanupTempFile($tempPath);
            $this->cleanupTempFile($tempOut);
        }
    }

    protected function optimize(Filesystem $storage, string $path, string $targetPath, ?int $quality): string
    {
        $tempPath = $this->toTempFile($storage, $path);

        try {
            $image = Image::useImageDriver(ImageDriver::Gd)->loadFile($tempPath);

            $image->quality($quality !== null ? max(1, min(100, $quality)) : 100)->save();
            $this->fromTempFile($storage, $targetPath, $tempPath);

            $newSize = $storage->size($targetPath);

            return "Optimized, saved as {$targetPath}. New size: {$newSize} bytes.";
        } finally {
            $this->cleanupTempFile($tempPath);
        }
    }

    private function toTempFile(Filesystem $storage, string $path): string
    {
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        $tempPath = sys_get_temp_dir().'/'.uniqid('imgmgr_').'.'.$ext;
        $written = file_put_contents($tempPath, $storage->get($path));

        if ($written === false) {
            throw new RuntimeException("Failed to write temp file: {$tempPath}");
        }

        return $tempPath;
    }

    private function fromTempFile(Filesystem $storage, string $path, string $tempPath): void
    {
        $storage->put($path, file_get_contents($tempPath));
    }

    private function cleanupTempFile(string $path): void
    {
        if (file_exists($path)) {
            unlink($path);
        }
    }
}
