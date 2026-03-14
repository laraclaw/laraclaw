<?php

namespace LaraClaw\Connectors;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use LaraClaw\DTOs\Attachment;
use LaraClaw\DTOs\IncomingMessage;
use LaraClaw\Enums\ConnectorType;
use LaraClaw\Models\Thread;
use LaraClaw\Services\Attachments;

class Api extends Connector
{
    public ConnectorType $type { get { return ConnectorType::Api; } }

    /**
     * Build an IncomingMessage from the validated API request data,
     * saving any uploaded files to inbound storage.
     *
     * @param  UploadedFile[]  $files
     */
    public static function createIncomingMessageFrom(
        ?string $text,
        string $key,
        array $files,
        Attachments $attachments,
    ): IncomingMessage {
        $uuid = (string) Str::uuid();

        return new IncomingMessage(
            text: $text,
            connector: ConnectorType::Api,
            key: $key,
            isDirectMessage: true,
            attachments: self::saveAttachments($files, $attachments->inbound($uuid)),
            uuid: $uuid,
        );
    }

    /**
     * Save uploaded files to inbound storage and return Attachment DTOs.
     *
     * @param  UploadedFile[]  $files
     * @return Attachment[]
     */
    private static function saveAttachments(array $files, Attachments $attachments): array
    {
        $disk = config('laraclaw.filesystem.attachments_disk', 'local');

        return collect($files)
            ->map(function (UploadedFile $file) use ($attachments, $disk): Attachment {
                $filename = basename($file->getClientOriginalName());
                $path = $attachments->putFile($filename, $file);

                return new Attachment(
                    path: $path,
                    disk: $disk,
                    mimeType: $file->getMimeType() ?? 'application/octet-stream',
                    filename: $filename,
                );
            })
            ->all();
    }

    /**
     * API conversations are always direct messages.
     */
    public static function isDirectMessage(string $key): bool
    {
        return true;
    }

    /**
     * Build an Api connector instance for outbound replies.
     */
    public static function forKey(string $key): self
    {
        return new self;
    }

    /**
     * API replies are returned inline by the controller, so this is a no-op.
     */
    public function reply(?Thread $thread, string $text, ?Collection $attachments = null): void
    {
        // The controller returns the agent response directly in the JSON body.
    }
}
