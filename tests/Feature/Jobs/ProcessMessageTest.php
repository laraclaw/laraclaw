<?php

use App\Ai\Agents\ChatBot;
use App\Channels\Contracts\ChannelDriver;
use App\Channels\DTOs\Attachment;
use App\Channels\DTOs\AttachmentType;
use App\Jobs\ProcessMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Files\StoredImage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Transcription;

uses(RefreshDatabase::class);

function mockDriver(array $overrides = []): ChannelDriver
{
    $driver = Mockery::mock(ChannelDriver::class);
    $driver->shouldReceive('platform')->andReturn($overrides['platform'] ?? 'telegram');
    $driver->shouldReceive('senderId')->andReturn($overrides['senderId'] ?? '12345');
    $driver->shouldReceive('attachments')->andReturn($overrides['attachments'] ?? collect());

    return $driver;
}

it('prompts the chatbot agent with the message text', function () {
    ChatBot::fake(['Hello, how can I help?']);

    $driver = mockDriver();
    $driver->shouldReceive('text')->andReturn('What is Laravel?');
    $driver->shouldReceive('reply')->once()->with('Hello, how can I help?');

    (new ProcessMessage($driver))->handle();

    ChatBot::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('What is Laravel?'));
});

it('handles null text gracefully', function () {
    ChatBot::fake(['I received an empty message.']);

    $driver = mockDriver();
    $driver->shouldReceive('text')->andReturn(null);
    $driver->shouldReceive('reply')->once()->with('I received an empty message.');

    (new ProcessMessage($driver))->handle();

    ChatBot::assertPrompted(fn (AgentPrompt $prompt) => $prompt->prompt === '');
});

it('continues the last conversation with the correct participant', function () {
    ChatBot::fake(['Sure, here is more info.']);

    $driver = mockDriver(['platform' => 'slack', 'senderId' => 'U999']);
    $driver->shouldReceive('text')->andReturn('Tell me more');
    $driver->shouldReceive('reply')->once();

    (new ProcessMessage($driver))->handle();

    ChatBot::assertPrompted(function (AgentPrompt $prompt) {
        $agent = $prompt->agent;

        return $agent->hasConversationParticipant()
            && $agent->conversationParticipant()->id === abs(crc32('slack:U999'));
    });
});

it('starts a fresh conversation when text is !new', function () {
    ChatBot::fake(['ignored']);

    $driver = mockDriver();
    $driver->shouldReceive('text')->andReturn('!new');
    $driver->shouldReceive('reply')->once()->with('Conversation reset. How can I help you?');

    (new ProcessMessage($driver))->handle();

    ChatBot::assertPrompted(function (AgentPrompt $prompt) {
        return $prompt->agent->hasConversationParticipant()
            && $prompt->contains('!new');
    });
});

it('transcribes audio when text is empty', function () {
    ChatBot::fake(['Got your voice message.']);
    Transcription::fake(['Hello from a voice message']);

    $attachment = new Attachment(AttachmentType::Audio, 'voice/msg.ogg', 'local');
    $driver = mockDriver(['attachments' => collect([$attachment])]);
    $driver->shouldReceive('text')->andReturn(null);
    $driver->shouldReceive('reply')->once()->with('Got your voice message.');

    (new ProcessMessage($driver))->handle();

    ChatBot::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('Hello from a voice message'));
});

it('prefers text over audio transcription when both exist', function () {
    ChatBot::fake(['Text reply.']);

    $attachment = new Attachment(AttachmentType::Audio, 'voice/msg.ogg', 'local');
    $driver = mockDriver(['attachments' => collect([$attachment])]);
    $driver->shouldReceive('text')->andReturn('Typed message');
    $driver->shouldReceive('reply')->once()->with('Text reply.');

    (new ProcessMessage($driver))->handle();

    ChatBot::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('Typed message'));
    Transcription::assertNothingGenerated();
});

it('passes image attachments to the agent', function () {
    ChatBot::fake(['Nice photo!']);

    $image = new Attachment(AttachmentType::Image, 'photos/cat.jpg', 'local', 'image/jpeg');
    $driver = mockDriver(['attachments' => collect([$image])]);
    $driver->shouldReceive('text')->andReturn('What is this?');
    $driver->shouldReceive('reply')->once()->with('Nice photo!');

    (new ProcessMessage($driver))->handle();

    ChatBot::assertPrompted(function (AgentPrompt $prompt) {
        return $prompt->attachments->count() === 1
            && $prompt->attachments->first() instanceof StoredImage
            && $prompt->attachments->first()->path === 'photos/cat.jpg';
    });
});

it('passes multiple image attachments to the agent', function () {
    ChatBot::fake(['Two images!']);

    $attachments = collect([
        new Attachment(AttachmentType::Image, 'photos/a.jpg', 'local', 'image/jpeg'),
        new Attachment(AttachmentType::Image, 'photos/b.png', 'local', 'image/png'),
        new Attachment(AttachmentType::Audio, 'voice/msg.ogg', 'local', 'audio/ogg'),
    ]);
    $driver = mockDriver(['attachments' => $attachments]);
    $driver->shouldReceive('text')->andReturn('Look at these');
    $driver->shouldReceive('reply')->once();

    (new ProcessMessage($driver))->handle();

    ChatBot::assertPrompted(fn (AgentPrompt $prompt) => $prompt->attachments->count() === 2);
});

it('sends no attachments when there are no images', function () {
    ChatBot::fake(['Ok!']);

    $driver = mockDriver();
    $driver->shouldReceive('text')->andReturn('Hello');
    $driver->shouldReceive('reply')->once();

    (new ProcessMessage($driver))->handle();

    ChatBot::assertPrompted(fn (AgentPrompt $prompt) => $prompt->attachments->isEmpty());
});
