<?php

use LaraClaw\Commands\NewConversation;
use LaraClaw\DTOs\IncomingMessage;
use LaraClaw\Enums\ChannelType;
use LaraClaw\Models\Thread;
use LaraClaw\Tests\Fixtures\FakeChannel;

it('clears the conversation id and returns null', function () {
    $user = $this->createUser();
    config(['laraclaw.auth.admin_user_id' => $user->id]);

    $message = new IncomingMessage(text: '!new', channel: ChannelType::Telegram, key: '123');
    $thread = Thread::forMessage($message);
    $thread->update(['conversation_id' => 'old-convo-id']);

    // Swap the resolved channel so we don't hit Telegram
    $fake = new FakeChannel;
    $thread->channel = ChannelType::Telegram;

    $command = new NewConversation;

    expect($command->trigger())->toBe('!new');

    // Use a mock thread that returns the fake channel
    $mockThread = Mockery::mock(Thread::class)->makePartial();
    $mockThread->allows('channel')->andReturn($fake);
    $mockThread->allows('update')->with(['conversation_id' => null])->once();

    $result = $command->handle($message, $mockThread);

    expect($result)->toBeNull()
        ->and($fake->sent)->toContain('✅ Conversation reset.');
});
