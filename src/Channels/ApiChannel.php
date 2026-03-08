<?php

namespace LaraClaw\Channels;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use LaraClaw\DTOs\Attachment;
use LaraClaw\DTOs\IncomingMessage;
use LaraClaw\Enums\ChannelType;
use LaraClaw\Models\Thread;
use LaraClaw\Services\Attachments;

class ApiChannel extends Channel
{
    public ChannelType $type { get { return ChannelType::Api; } }

    /**
     * Build an IncomingMessage from the validated API request data,
     * saving any uploaded files to inbound storage.
     *
     * @param  UploadedFile[]  $files
     */
    public static function createIncomingMessageFrom(
        ?string $text,
        int|string $userId,
        array $files,
        Attachments $attachments,
    ): IncomingMessage {
        $uuid = (string) Str::uuid();

        return new IncomingMessage(
            text: $text,
            channel: ChannelType::Api,
            key: (string) $userId,
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
                $filename = $file->getClientOriginalName();
                $path = $attachments->set($filename, $file->get());

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
     * Build an ApiChannel instance for outbound replies.
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
