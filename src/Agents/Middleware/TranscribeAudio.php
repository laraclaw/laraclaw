<?php

namespace Laraclaw\Agents\Middleware;

use Closure;
use Illuminate\Support\Facades\Log;
use Laraclaw\DTOs\Attachment;
use Laraclaw\DTOs\IncomingMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Transcription;
use Throwable;

class TranscribeAudio
{
    /**
     * Bind the inbound message so audio attachments can be located when the prompt is empty.
     */
    public function __construct(
        private readonly IncomingMessage $message,
    ) {}

    /**
     * Transcribe the first audio attachment when the sender wrote no text of their own.
     */
    public function handle(AgentPrompt $prompt, Closure $next): mixed
    {
        // Test the message rather than the prompt. A voice note carries no text, but
        // the prompt still lists the attached file, so it is never blank and this
        // used to skip transcription for exactly the messages that needed it.
        if (blank($this->message->text)) {
            $audio = collect($this->message->attachments)->first(fn (Attachment $a): bool => $a->isAudio());

            if ($audio) {
                return $next($this->transcribe($prompt, $audio));
            }
        }

        return $next($prompt);
    }

    /**
     * Return the prompt with the transcript on top, or with a note when the audio could not be read.
     */
    private function transcribe(AgentPrompt $prompt, Attachment $audio): AgentPrompt
    {
        try {
            $transcribed = Transcription::fromStorage($audio->path, $audio->disk)->generate()->text;
        } catch (Throwable $e) {
            // Audio we cannot transcribe should not take the whole message down.
            // Letting this bubble leaves the sender staring at silence, so tell
            // the agent what happened and let it answer for itself.
            Log::warning('Audio transcription failed', [
                'path' => $audio->path,
                'mimeType' => $audio->mimeType,
                'error' => $e->getMessage(),
            ]);

            return $prompt->prepend('The user sent a voice message that could not be transcribed. Say so and ask them to type it instead.');
        }

        // Prepend rather than replace so the attachment notes survive and
        // tools can still reach the original file on disk.
        return $prompt->prepend($transcribed);
    }
}
