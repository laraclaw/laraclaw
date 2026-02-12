<?php

use App\Ai\Agents\ChatBot;
use App\Channels\Contracts\ChannelDriver;
use App\Jobs\ProcessMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Prompts\AgentPrompt;

uses(RefreshDatabase::class);

function mockDriver(array $overrides = []): ChannelDriver
{
    $driver = Mockery::mock(ChannelDriver::class);
    $driver->shouldReceive('platform')->andReturn($overrides['platform'] ?? 'telegram');
    $driver->shouldReceive('senderId')->andReturn($overrides['senderId'] ?? '12345');

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
