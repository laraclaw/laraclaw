<?php

namespace Laraclaw\Agents;

use Illuminate\Support\Facades\Log;
use Laraclaw\DTOs\Attachment;
use Laraclaw\DTOs\IncomingMessage;
use Laravel\Ai\Transcription;
use Throwable;

/**
 * Turns a voice note into the text the agent is actually prompted with.
 */
class TranscribeAudio
{
    /**
     * Bind the inbound message so audio attachments can be located when the prompt is empty.
     */
    public function __construct(
        private readonly IncomingMessage $message,
    ) {}

    /**
     * Put the transcript of the first audio attachment on top of the prompt when the sender wrote no text of their own.
     */
    public function handle(string $prompt): string
    {
        // Test the message rather than the prompt. A voice note carries no text, but
        // the prompt still lists the attached file, so it is never blank and this
        // used to skip transcription for exactly the messages that needed it.
        if (filled($this->message->text)) {
            return $prompt;
        }

        $audio = collect($this->message->attachments)->first(fn (Attachment $a): bool => $a->isAudio());

        if (! $audio) {
            return $prompt;
        }

        // Prepend rather than replace so the attachment notes survive and
        // tools can still reach the original file on disk.
        return $this->transcribe($audio) . PHP_EOL . PHP_EOL . $prompt;
    }

    /**
     * Return the transcript, or a note for the agent when the audio could not be read.
     */
    private function transcribe(Attachment $audio): string
    {
        try {
            return Transcription::fromStorage($audio->path, $audio->disk)->generate()->text;
        } catch (Throwable $e) {
            // Audio we cannot transcribe should not take the whole message down.
            // Letting this bubble leaves the sender staring at silence, so tell
            // the agent what happened and let it answer for itself.
            Log::warning('Audio transcription failed', [
                'path' => $audio->path,
                'mimeType' => $audio->mimeType,
                'error' => $e->getMessage(),
            ]);

            return 'The user sent a voice message that could not be transcribed. Say so and ask them to type it instead.';
        }
    }
}
