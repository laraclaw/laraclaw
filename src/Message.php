<?php

namespace LaraClaw;

use Illuminate\Support\Collection;
use LaraClaw\Channels\Channel;
use LaraClaw\DTOs\Attachment;
use LaraClaw\Models\UserAccount;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Transcription;

/**
 * Domain object representing an inbound message from any channel.
 */
class Message
{
    public function __construct(
        public readonly Channel $channel,
        public readonly string $conversationKey,
        public readonly bool $conversationIsDirectMessage,
        public readonly ?string $text = null,
        public readonly Collection $attachments = new Collection,
    ) {}

    /**
     * Return a copy of this message with the given text.
     */
    public function withText(string $text): self
    {
        return new self(
            $this->channel,
            $this->conversationKey,
            $this->conversationIsDirectMessage,
            $text,
            $this->attachments,
        );
    }

    /**
     * Return the UserAccount row for the admin user on the given channel, or null if none is registered.
     *
     * @return array{0: \LaraClaw\Enums\ChannelType, 1: string}|null
     */
    public function adminAccountForChannel(string $channelType): ?array
    {
        $account = UserAccount::where('user_id', config('laraclaw.auth.admin_user_id'))
            ->where('channel', $channelType)
            ->first();

        if (! $account) {
            return null;
        }

        return [$account->channel, $account->account];
    }

    /**
     * Get the message text, transcribing any audio attachment if no text was provided.
     * Appends file metadata at the end so the agent knows where to find the attachments.
     */
    public function agentText(): string
    {
        $text = $this->text ?? '';

        if (blank($text)) {
            $audio = $this->attachments->first(fn ($a) => $a->isAudio());
            if ($audio) {
                $text = Transcription::fromStorage($audio->path, $audio->disk)->generate()->text;
            }
        }

        $attachmentMeta = $this->attachments
            ->filter(fn ($a) => $a->isImage() || $a->isDocument())
            ->map(fn ($a) => ['type' => $a->mimeType, 'disk' => $a->disk, 'path' => $a->path])
            ->values()
            ->all();

        if (! empty($attachmentMeta)) {
            $text .= PHP_EOL . PHP_EOL . '[Attached files: ' . json_encode($attachmentMeta) . ']';
        }

        return $text;
    }

    /**
     * Build the list of images and documents the agent will receive alongside the message text.
     *
     * @return array<int, Image|Document>
     */
    public function agentAttachments(): array
    {
        return $this->attachments
            ->map(fn (Attachment $a) => match (true) {
                $a->isImage() => Image::fromStorage($a->path, $a->disk),
                $a->isDocument() => Document::fromStorage($a->path, $a->disk),
                default => null,
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Determine whether this DM originated from an unregistered account.
     * Always returns false for group/open channel messages.
     */
    public function isFromUnrecognizedAccount(): bool
    {
        if (! $this->conversationIsDirectMessage) {
            return false;
        }

        return ! UserAccount::where('channel', $this->channel->name)
            ->where('account', $this->conversationKey)
            ->exists();
    }
}
