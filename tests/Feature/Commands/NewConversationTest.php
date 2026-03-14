<?php

use LaraClaw\Commands\NewConversation;
use LaraClaw\DTOs\IncomingMessage;
use LaraClaw\Enums\ConnectorType;
use LaraClaw\Models\Thread;
use LaraClaw\Tests\Fixtures\Fake;

it('clears the conversation id and returns null', function () {
    $user = $this->createUser();
    config(['laraclaw.auth.admin_user_id' => $user->id]);

    $message = new IncomingMessage(text: '!new', connector: ConnectorType::Telegram, key: '123');
    $thread = Thread::forMessage($message);
    $thread->update(['conversation_id' => 'old-convo-id']);

    // Swap the resolved connector so we don't hit Telegram
    $fake = new Fake;
    $thread->connector = ConnectorType::Telegram;

    $command = new NewConversation;

    expect($command->trigger())->toBe('!new');

    // Use a mock thread that returns the fake connector
    $mockThread = Mockery::mock(Thread::class)->makePartial();
    $mockThread->allows('connector')->andReturn($fake);
    $mockThread->allows('update')->with(['conversation_id' => null])->once();

    $result = $command->handle($message, $mockThread);

    expect($result)->toBeNull()
        ->and($fake->sent)->toContain('✅ Conversation reset.');
});
