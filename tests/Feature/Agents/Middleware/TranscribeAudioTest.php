<?php

use Laraclaw\Agents\Middleware\TranscribeAudio;
use Laraclaw\DTOs\Attachment;
use Laraclaw\DTOs\IncomingMessage;
use Laraclaw\Enums\ConnectorType;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Transcription;

function voiceNote(?string $text = null): IncomingMessage
{
    return new IncomingMessage(
        text: $text,
        connector: ConnectorType::Telegram,
        key: '123',
        isDirectMessage: true,
        attachments: [new Attachment(
            path: 'inbound/uuid/voice.oga',
            disk: 'local',
            mimeType: 'audio/ogg',
            filename: 'voice.oga',
        )],
        uuid: 'msg-uuid',
    );
}

/**
 * Run the middleware and hand back the prompt text it passed downstream.
 */
function transcribedText(IncomingMessage $message): ?string
{
    [$text, $files] = $message->toAgentInput();

    $prompt = new AgentPrompt(
        agent: Mockery::mock(Agent::class),
        prompt: $text ?? '',
        attachments: $files,
        provider: Mockery::mock(TextProvider::class),
        model: 'test-model',
    );

    $seen = null;

    (new TranscribeAudio($message))->handle($prompt, function (AgentPrompt $p) use (&$seen) {
        $seen = $p->prompt;

        return $p;
    });

    return $seen;
}

it('transcribes a voice note even though the prompt lists the attached file', function () {
    // The prompt always names the attachment, so it is never blank. Keying off
    // that emptiness meant voice notes went to the model untranscribed.
    Transcription::fake(['walk the dog at six']);

    expect(transcribedText(voiceNote()))->toContain('walk the dog at six');
});

it('keeps the attachment notes below the transcript', function () {
    Transcription::fake(['walk the dog at six']);

    $result = transcribedText(voiceNote());

    expect($result)->toStartWith('walk the dog at six')
        ->and($result)->toContain('inbound/uuid/voice.oga');
});

it('leaves the prompt alone when the sender wrote their own text', function () {
    Transcription::fake(['should never be used']);

    expect(transcribedText(voiceNote('here is a note')))
        ->toContain('here is a note')
        ->not->toContain('should never be used');
});

it('leaves the prompt alone when there is no audio', function () {
    Transcription::fake(['should never be used']);

    $message = new IncomingMessage(
        text: null,
        connector: ConnectorType::Telegram,
        key: '123',
        attachments: [new Attachment(path: 'inbound/uuid/photo.jpg', disk: 'local', mimeType: 'image/jpeg', filename: 'photo.jpg')],
    );

    expect(transcribedText($message))->not->toContain('should never be used');
});
