<?php

namespace Laraclaw\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

/**
 * Hold every attachment that enters the package to one configurable size budget.
 *
 * Web server limits only cover the entry points that arrive as an HTTP upload.
 * A file the bot pulls down itself, from Telegram, Slack, or a mailbox, never
 * passes through those limits, so without this budget one huge remote file can
 * exhaust PHP memory, storage, or the model upload quota.
 */
class AttachmentSizeGuard
{
    /**
     * Read a download in slices this big so nothing oversized lands in memory whole.
     */
    private const int CHUNK_BYTES = 8192;

    /**
     * Return the size budget in kilobytes, the unit Laravel's max rule expects for files.
     */
    public static function maxKilobytes(): int
    {
        return (int) config('laraclaw.filesystem.max_attachment_kilobytes', 20480);
    }

    /**
     * Return the same budget in bytes, for measuring content we already hold.
     */
    public static function maxBytes(): int
    {
        return self::maxKilobytes() * 1024;
    }

    /**
     * Check whether content of the given byte length is within the budget.
     */
    public static function fits(int $bytes): bool
    {
        return $bytes <= self::maxBytes();
    }

    /**
     * Return the response body, or null when the file is bigger than the budget.
     *
     * The body is pulled in chunks and abandoned as soon as it crosses the line, so
     * an oversized download is never buffered in full just to be measured. Callers
     * are expected to have checked the status code already.
     */
    public static function body(Response $response, array $context = []): ?string
    {
        $limit = self::maxBytes();
        $declared = (int) $response->header('Content-Length');

        if ($declared > $limit) {
            self::reject($declared, $context);

            return null;
        }

        $stream = $response->toPsrResponse()->getBody();

        // A stream that already knows its length tells us to stop before we read at all.
        $size = $stream->getSize();

        if ($size !== null && $size > $limit) {
            self::reject($size, $context);

            return null;
        }

        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        $body = '';

        while (! $stream->eof()) {
            $body .= $stream->read(self::CHUNK_BYTES);

            if (strlen($body) > $limit) {
                self::reject(strlen($body), $context);

                return null;
            }
        }

        return $body;
    }

    /**
     * Log a refused attachment with enough context to trace which download it was.
     */
    public static function reject(int $bytes, array $context = []): void
    {
        Log::warning('Attachment exceeds the configured size limit', array_merge($context, [
            'bytes' => $bytes,
            'limit_bytes' => self::maxBytes(),
        ]));
    }
}
