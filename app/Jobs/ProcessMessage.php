<?php

namespace App\Jobs;

use App\Ai\Agents\ChatBot;
use App\Ai\ConversationParticipant;
use App\Channels\Contracts\ChannelDriver;
use App\Channels\DTOs\Attachment;
use App\Channels\DTOs\AttachmentType;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Transcription;

class ProcessMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private ChannelDriver $driver,
    ) {}

    public function handle(): void
    {
        $this->driver->sendTypingIndicator();

        $text = $this->driver->text() ?? '';

        if ($text === '' && $audio = $this->audioAttachment()) {
            $text = Transcription::fromStorage($audio->path, $audio->disk)->generate()->text;
        }

        $participant = new ConversationParticipant($this->driver->platform() . ':' . $this->driver->senderId());

        $attachments = [...$this->imageAttachments(), ...$this->documentAttachments()];
        $text = $this->appendAttachmentPaths($text);

        if (strtolower(trim($text)) === '!new') {
            $this->driver->reply('Conversation reset. How can I help you?');

            return;
        }

        $response = ChatBot::make()->continueLastConversation(as: $participant)->prompt($text, attachments: $attachments);

        $this->driver->reply((string) $response);
    }

    private function audioAttachment(): ?Attachment
    {
        return $this->driver->attachments()->first(fn (Attachment $a) => $a->type === AttachmentType::Audio);
    }

    /** @return array<Image> */
    private function imageAttachments(): array
    {
        return $this->driver->attachments()
            ->filter(fn (Attachment $a) => $a->type === AttachmentType::Image)
            ->map(fn (Attachment $a) => Image::fromStorage($a->path, $a->disk))
            ->values()
            ->all();
    }

    /** @return array<Document> */
    private function documentAttachments(): array
    {
        return $this->driver->attachments()
            ->filter(fn (Attachment $a) => $a->type === AttachmentType::Document)
            ->map(fn (Attachment $a) => Document::fromStorage($a->path, $a->disk))
            ->values()
            ->all();
    }

    private function appendAttachmentPaths(string $text): string
    {
        $paths = $this->driver->attachments()
            ->filter(fn (Attachment $a) => $a->type !== AttachmentType::Audio)
            ->map(fn (Attachment $a) => "[{$a->type->value}: {$a->path}]")
            ->implode("\n");

        return $paths !== '' ? $text . "\n\n" . $paths : $text;
    }
}
