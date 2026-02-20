<?php

namespace LaraClaw\Tools;

use LaraClaw\PendingAudioReply;
use Exception;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Audio;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class TextToSpeech implements Tool
{
    public function __construct(private PendingAudioReply $audioReply) {}

    public function description(): Stringable|string
    {
        return 'Convert text to speech. Use when the user asks you to reply with audio, send a voice message, or speak your response. The audio will be attached to your text reply automatically.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'text' => $schema->string()->required()->description('The text to convert to speech'),
            'voice' => $schema->string()->description('Voice to use (default-male, default-female, or a provider-specific voice ID)'),
            'instructions' => $schema->string()->description('Instructions for how the audio should sound (e.g. "speak slowly and calmly")'),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $text = $request['text'] ?? null;

        if ($text === null || trim($text) === '') {
            return 'The "text" parameter is required.';
        }

        try {
            $pending = Audio::of($text);

            $voice = $request['voice'] ?? config('laraclaw.tts.voice', 'default-female');
            $pending->voice($voice);

            if (! empty($request['instructions'])) {
                $pending->instructions($request['instructions']);
            }

            $response = $pending->generate();

            $this->audioReply->path = $this->storeTempFile($response->content());

            return 'Audio generated. Reply to the user normally — the audio will be attached automatically.';
        } catch (Exception $e) {
            return "Text-to-speech failed: {$e->getMessage()}";
        }
    }

    private function storeTempFile(string $audioContent): string
    {
        $path = sys_get_temp_dir().'/'.Str::uuid().'.mp3';

        file_put_contents($path, $audioContent);

        return $path;
    }
}
