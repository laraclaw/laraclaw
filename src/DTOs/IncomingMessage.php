<?php

namespace Laraclaw\DTOs;

use Illuminate\Support\Str;
use Laraclaw\Enums\ConnectorType;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\Image;

/**
 * DTO representing an incoming message from any connector before it is handed to the agent.
 */
class IncomingMessage
{
    public readonly string $uuid;

    /**
     * @param  Attachment[]  $attachments
     */
    public function __construct(
        public readonly ?string $text,
        public readonly ConnectorType $connector,
        public readonly string $key,
        public readonly bool $isDirectMessage = false,
        public readonly array $attachments = [],
        ?string $uuid = null,
        public readonly ?string $senderName = null,
    ) {
        $this->uuid = $uuid ?? (string) Str::uuid();
    }

    /**
     * Return the text and AI file attachments as a two-element array ready to spread into queue().
     *
     * Audio is left out on purpose. It reaches the model as a transcript instead,
     * and handing a voice note to a text provider as a document makes it reject
     * the whole request rather than just ignoring the file.
     */
    public function toAgentInput(): array
    {
        return [
            $this->textWithAttachmentsNotes(),
            collect($this->attachments)
                ->reject(fn (Attachment $a): bool => $a->isAudio())
                ->map(fn (Attachment $a): Image|Document => $a->toAiFile())
                ->values()
                ->all(),
        ];
    }

    /**
     * Return the message text with file metadata appended so the agent
     * knows the disk and path of each attachment for tool use.
     */
    private function textWithAttachmentsNotes(): ?string
    {
        if ($this->attachments === []) {
            return $this->text;
        }

        $meta = collect($this->attachments)
            ->map(fn (Attachment $a): string => "- {$a->filename} ({$a->mimeType}) disk={$a->disk} path={$a->path}")
            ->implode("\n");

        return trim("{$this->text}\n\nAttached files:\n{$meta}");
    }

    /**
     * Return the fields persisted when this DTO is queued, including the resolved UUID.
     */
    public function __serialize(): array
    {
        return [
            'uuid' => $this->uuid,
            'text' => $this->text,
            'connector' => $this->connector,
            'key' => $this->key,
            'isDirectMessage' => $this->isDirectMessage,
            'attachments' => $this->attachments,
            'senderName' => $this->senderName,
        ];
    }

    /**
     * Restore the readonly properties from the serialized payload after a job thaw.
     *
     * Newer keys are read defensively so a job queued before an upgrade still thaws.
     */
    public function __unserialize(array $data): void
    {
        $this->uuid = $data['uuid'];
        $this->text = $data['text'];
        $this->connector = $data['connector'];
        $this->key = $data['key'];
        $this->isDirectMessage = $data['isDirectMessage'];
        $this->attachments = $data['attachments'];
        $this->senderName = $data['senderName'] ?? null;
    }
}
