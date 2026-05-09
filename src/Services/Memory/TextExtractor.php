<?php

namespace Laraclaw\Services\Memory;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Laraclaw\DTOs\Attachment;
use Spatie\PdfToText\Pdf;
use thiagoalessio\TesseractOCR\TesseractOCR;
use Throwable;

class TextExtractor
{
    private static bool $pdfWarned = false;

    private static bool $ocrWarned = false;

    /**
     * Extract readable text from an attachment. Returns null when extraction
     * is not possible or the required package is missing.
     */
    public function extract(Attachment $attachment): ?string
    {
        $disk = Storage::disk($attachment->disk);

        if (! $disk->exists($attachment->path)) {
            return null;
        }

        $path = $disk->path($attachment->path);

        return match (true) {
            $attachment->isImage() => $this->ocr($path),
            $attachment->mimeType === 'application/pdf' => $this->pdf($path),
            in_array($attachment->mimeType, ['text/plain', 'text/markdown', 'text/csv', 'text/html'], true) => $this->text($path),
            default => null,
        };
    }

    private function ocr(string $path): ?string
    {
        if (! class_exists(TesseractOCR::class)) {
            return $this->warnOnce(self::$ocrWarned, 'install thiagoalessio/tesseract_ocr to enable OCR');
        }

        return $this->tryExtract(fn (): string => new TesseractOCR($path)->run(), $path);
    }

    private function pdf(string $path): ?string
    {
        if (! class_exists(Pdf::class)) {
            return $this->warnOnce(self::$pdfWarned, 'install spatie/pdf-to-text to enable PDF extraction');
        }

        return $this->tryExtract(fn (): string => Pdf::getText($path), $path);
    }

    private function text(string $path): ?string
    {
        return $this->tryExtract(fn (): string => file_get_contents($path), $path);
    }

    private function tryExtract(callable $fn, string $path): ?string
    {
        try {
            $text = trim((string) $fn());

            return $text !== '' ? $text : null;
        } catch (Throwable $e) {
            Log::warning('Text extraction failed', ['path' => $path, 'error' => $e->getMessage()]);

            return null;
        }
    }

    private function warnOnce(bool &$flag, string $message): null
    {
        if (! $flag) {
            Log::info("Attachment skipped: {$message}.");
            $flag = true;
        }

        return null;
    }
}
