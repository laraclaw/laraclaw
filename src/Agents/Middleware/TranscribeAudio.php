<?php

namespace Laraclaw\Agents\Middleware;

use Closure;
use Laraclaw\DTOs\Attachment;
use Laraclaw\DTOs\IncomingMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Transcription;

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
                $transcribed = Transcription::fromStorage($audio->path, $audio->disk)->generate()->text;

                // Prepend rather than replace so the attachment notes survive and
                // tools can still reach the original file on disk.
                return $next($prompt->prepend($transcribed));
            }
        }

        return $next($prompt);
    }
}
