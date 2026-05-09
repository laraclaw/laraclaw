<?php

namespace Laraclaw\Tools;

use Exception;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laraclaw\DTOs\IncomingMessage;
use Laraclaw\Services\Attachments;
use Laravel\Ai\Audio;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Agent tool that converts text to speech and appends the audio to the reply.
 */
class TextToSpeech implements Tool
{
    /**
     * Bind the inbound message and the attachment writer used to stage the generated audio.
     */
    public function __construct(private readonly IncomingMessage $message, private readonly Attachments $attachments) {}

    /**
     * Return the tool description shown to the agent.
     */
    public function description(): Stringable|string
    {
        return 'Convert text to speech. Use when the user asks you to reply with audio, send a voice message, or speak your response. The audio will be attached to your text reply automatically.';
    }

    /**
     * Define the input schema for this tool.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'text' => $schema->string()->required()->description('The text to convert to speech'),
            'voice' => $schema->string()->description('Voice to use (default-male, default-female, or a provider-specific voice ID)'),
            'instructions' => $schema->string()->description('Instructions for how the audio should sound (e.g. "speak slowly and calmly")'),
        ];
    }

    /**
     * Generate speech audio from the given text and attach it to the reply.
     */
    public function handle(Request $request): Stringable|string
    {
        $text = $request['text'] ?? null;

        if ($text === null || trim((string) $text) === '') {
            return 'The "text" parameter is required.';
        }

        try {
            $pending = Audio::of($text);

            $voice = $request['voice'] ?? config('laraclaw.tools.tts.voice', 'default-female');
            $pending->voice($voice);

            if (! empty($request['instructions'])) {
                $pending->instructions($request['instructions']);
            }

            $response = $pending->generate();

            $this->attachments->outbound($this->message->uuid)->set('voice.mp3', $response->content());

            return 'Audio generated. Reply to the user normally — the audio will be attached automatically.';
        } catch (Exception $e) {
            return "Text-to-speech failed: {$e->getMessage()}";
        }
    }
}
