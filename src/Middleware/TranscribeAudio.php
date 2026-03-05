<?php

namespace LaraClaw\Middleware;

use Closure;
use LaraClaw\DTOs\Attachment;
use LaraClaw\Message;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Transcription;

class TranscribeAudio
{
    public function __construct(
        private readonly Message $message,
    ) {}

    /**
     * Transcribe the first audio attachment if the incoming prompt has no text.
     */
    public function handle(AgentPrompt $prompt, Closure $next): mixed
    {
        if (blank($prompt->prompt)) {
            $audio = $this->message->attachments->first(fn (Attachment $a): bool => $a->isAudio());

            if ($audio) {
                $transcribed = Transcription::fromStorage($audio->path, $audio->disk)->generate()->text;

                return $next($prompt->revise($transcribed));
            }
        }

        return $next($prompt);
    }
}
