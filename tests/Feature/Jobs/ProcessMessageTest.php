<?php

use App\Ai\Agents\ChatBot;
use App\Channels\Contracts\ChannelDriver;
use App\Jobs\ProcessMessage;
use Laravel\Ai\Prompts\AgentPrompt;

it('prompts the chatbot agent with the message text', function () {
    ChatBot::fake(['Hello, how can I help?']);

    $driver = Mockery::mock(ChannelDriver::class);
    $driver->shouldReceive('text')->andReturn('What is Laravel?');
    $driver->shouldReceive('reply')->once()->with('Hello, how can I help?');

    (new ProcessMessage($driver))->handle();

    ChatBot::assertPrompted(fn (AgentPrompt $prompt) => $prompt->contains('What is Laravel?'));
});

it('handles null text gracefully', function () {
    ChatBot::fake(['I received an empty message.']);

    $driver = Mockery::mock(ChannelDriver::class);
    $driver->shouldReceive('text')->andReturn(null);
    $driver->shouldReceive('reply')->once()->with('I received an empty message.');

    (new ProcessMessage($driver))->handle();

    ChatBot::assertPrompted(fn (AgentPrompt $prompt) => $prompt->prompt === '');
});
