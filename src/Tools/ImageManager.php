<?php

namespace Laraclaw\Tools;

use Closure;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Storage;
use Laraclaw\DTOs\IncomingMessage;
use Laraclaw\Services\Attachments;
use Laravel\Ai\Tools\Request;
use Override;
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
    private const array ORIENTATIONS = ['rotate_90', 'rotate_180', 'rotate_270', 'flip_horizontal', 'flip_vertical'];

    private const array FORMATS = ['jpg', 'png', 'webp'];

    protected array $requires = [
        'orient' => ['orientation'],
        'convert' => ['format'],
    ];

    /**
     * Bind the inbound message and the attachment writer used to stage outbound images.
     */
    public function __construct(protected IncomingMessage $message, private readonly Attachments $attachments) {}

    /**
     * Return the tool description shown to the agent.
     */
    public function description(): Stringable|string
    {
        $disks = implode(', ', config('laraclaw.filesystem.allowed_disks', []));

        return "Work with images: get info, resize, crop, orient, convert, optimize. Allowed disks: {$disks}. Operations: " . implode(', ', $this->operations()) . '. After any write operation (resize, crop, orient, convert, optimize) the resulting image is automatically sent to the user, so do NOT say you cannot send files.';
    }

    /**
     * Define the input schema for this tool.
     */
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
            'orientation' => $schema->string()->description('For orient: ' . implode(', ', self::ORIENTATIONS)),
        ];
    }

    /**
     * Check the operation, the disk, the path and that the file really is an image before dispatching.
     */
    #[Override]
    public function handle(Request $request): Stringable|string
    {
        if ($error = $this->validateOperation($request) ?? $this->validateDiskAccess($request['disk'] ?? '', $request['path'] ?? '')) {
            return $error;
        }

        $storage = $this->storage($request);
        $path = $request['path'];

        if (! $storage->exists($path)) {
            return "File not found: {$path}";
        }

        if (! str_starts_with($storage->mimeType($path) ?: '', 'image/')) {
            return "Not an image file: {$path}";
        }

        return parent::handle($request);
    }

    /**
     * Return the list of supported operation names.
     *
     * @return string[]
     */
    protected function operations(): array
    {
        return ['info', 'resize', 'crop', 'orient', 'convert', 'optimize'];
    }

    /**
     * Return width, height, MIME type, and file size for an image.
     */
    protected function info(Request $request): string
    {
        $storage = $this->storage($request);
        $temporary = $this->temporaryCopy($storage, $request['path']);

        try {
            $image = $this->open($temporary);

            return json_encode([
                'width' => $image->getWidth(),
                'height' => $image->getHeight(),
                'mime' => $storage->mimeType($request['path']),
                'size' => $storage->size($request['path']),
            ], JSON_PRETTY_PRINT);
        } finally {
            $this->forget($temporary);
        }
    }

    /**
     * Resize an image to the given width and/or height. Aspect ratio is preserved when only one dimension is provided.
     */
    protected function resize(Request $request): string
    {
        $width = $request->integer('width') ?: null;
        $height = $request->integer('height') ?: null;

        if ($width === null && $height === null) {
            return 'At least one of "width" or "height" is required for resize.';
        }

        return $this->transform($request, $this->siblingPath($request['path'], '_resized'), function (Image $image) use ($width, $height): string {
            if ($width !== null) {
                $image->width($width);
            }

            if ($height !== null) {
                $image->height($height);
            }

            return "Resized to {$image->getWidth()}x{$image->getHeight()}";
        });
    }

    /**
     * Crop an image to exact dimensions from the center.
     */
    protected function crop(Request $request): string
    {
        $width = $request->integer('width') ?: null;
        $height = $request->integer('height') ?: null;

        if ($width === null || $height === null) {
            return 'Both "width" and "height" are required for crop.';
        }

        return $this->transform($request, $this->siblingPath($request['path'], '_cropped'), function (Image $image) use ($width, $height): string {
            $image->crop($width, $height);

            return "Cropped to {$width}x{$height}";
        });
    }

    /**
     * Rotate or flip an image using one of the supported orientation values.
     */
    protected function orient(Request $request): string
    {
        $orientation = $request['orientation'];

        if (! in_array($orientation, self::ORIENTATIONS, true)) {
            return "Unknown orientation '{$orientation}'. Use: " . implode(', ', self::ORIENTATIONS) . '.';
        }

        return $this->transform($request, $this->siblingPath($request['path'], "_{$orientation}"), function (Image $image) use ($orientation): string {
            match ($orientation) {
                'rotate_90' => $image->orientation(Orientation::Rotate90),
                'rotate_180' => $image->orientation(Orientation::Rotate180),
                'rotate_270' => $image->orientation(Orientation::Rotate270),
                'flip_horizontal' => $image->flip(FlipDirection::Horizontal),
                'flip_vertical' => $image->flip(FlipDirection::Vertical),
            };

            return "Applied {$orientation}";
        });
    }

    /**
     * Convert an image to a different format (jpg, png, or webp).
     */
    protected function convert(Request $request): string
    {
        $format = $request['format'];

        if (! in_array($format, self::FORMATS, true)) {
            return 'The "format" parameter must be one of: ' . implode(', ', self::FORMATS) . '.';
        }

        return $this->transform($request, $this->siblingPath($request['path'], '', $format), fn (): string => "Converted {$request['path']}");
    }

    /**
     * Save the image again at a lower quality level to reduce file size.
     */
    protected function optimize(Request $request): string
    {
        $quality = isset($request['quality']) ? max(1, min(100, (int) $request['quality'])) : 100;
        $target = $this->siblingPath($request['path'], '_optimized');

        return $this->transform($request, $target, fn (): string => 'Optimized', $quality)
            . " New size: {$this->storage($request)->size($target)} bytes.";
    }

    /**
     * Load the image, apply the edit, write the result to the target path and queue it for the reply.
     *
     * Spatie applies each operation as it is called, so the edit closure can read
     * the new dimensions straight away and return the first half of the message.
     * The output takes the target's extension, which is how a format conversion
     * happens without a dedicated step.
     */
    private function transform(Request $request, string $target, Closure $edit, int $quality = 100): string
    {
        $storage = $this->storage($request);
        $temporary = $this->temporaryCopy($storage, $request['path']);
        $output = $temporary . '.out.' . pathinfo($target, PATHINFO_EXTENSION);

        try {
            $image = $this->open($temporary);
            $message = $edit($image);

            $image->quality($quality)->save($output);
            $storage->put($target, file_get_contents($output));
            $this->attachments->outbound($this->message->uuid)->set(basename($target), $storage->get($target));

            return "{$message}, saved as {$target}.";
        } finally {
            $this->forget($temporary);
            $this->forget($output);
        }
    }

    /**
     * Open a local file with the configured Spatie driver (imagick or gd).
     */
    private function open(string $path): Image
    {
        $driver = config('laraclaw.tools.image_manager.driver', 'imagick') === 'gd' ? ImageDriver::Gd : ImageDriver::Imagick;

        return Image::useImageDriver($driver)->loadFile($path);
    }

    /**
     * Build a path beside the original with a suffix before the extension, optionally swapping the extension.
     */
    private function siblingPath(string $path, string $suffix, ?string $extension = null): string
    {
        $dir = dirname($path) === '.' ? '' : dirname($path) . '/';
        $extension ??= pathinfo($path, PATHINFO_EXTENSION);

        return $dir . pathinfo($path, PATHINFO_FILENAME) . $suffix . ($extension !== '' ? '.' . $extension : '');
    }

    /**
     * Copy a storage file to a local temp path so Spatie Image can process it.
     */
    private function temporaryCopy(Filesystem $storage, string $path): string
    {
        $temporary = sys_get_temp_dir() . '/' . uniqid('imgmgr_') . '.' . pathinfo($path, PATHINFO_EXTENSION);

        if (file_put_contents($temporary, $storage->get($path)) === false) {
            throw new RuntimeException("Failed to write temp file: {$temporary}");
        }

        return $temporary;
    }

    /**
     * Delete a local temp file if it exists.
     */
    private function forget(string $path): void
    {
        if (file_exists($path)) {
            unlink($path);
        }
    }
}
