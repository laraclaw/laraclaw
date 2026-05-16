<?php

use Laraclaw\DTOs\IncomingMessage;
use Laraclaw\Enums\ConnectorType;
use Laraclaw\Models\Account;
use Laraclaw\Models\Thread;

beforeEach(function () {
    $this->user = $this->createUser();
    config(['laraclaw.auth.admin_user_id' => $this->user->id]);
});

it('creates a thread from an incoming message', function () {
    $msg = new IncomingMessage(text: 'hi', connector: ConnectorType::Telegram, key: 'chat-42', isDirectMessage: true);

    $thread = Thread::forMessage($msg);

    expect($thread->connector)->toBe(ConnectorType::Telegram)
        ->and($thread->key)->toBe('chat-42')
        ->and($thread->is_direct_message)->toBeTrue();
});

it('finds the existing thread on second call', function () {
    $msg = new IncomingMessage(text: 'hi', connector: ConnectorType::Slack, key: 'C123');

    $first = Thread::forMessage($msg);
    $second = Thread::forMessage($msg);

    expect($first->id)->toBe($second->id);
});

it('returns admin user for group threads', function () {
    $msg = new IncomingMessage(text: 'hi', connector: ConnectorType::Telegram, key: 'group-1', isDirectMessage: false);
    $thread = Thread::forMessage($msg);

    $user = $thread->user();

    expect($user->getAuthIdentifier())->toBe($this->user->id);
});

it('returns the registered account owner for DM threads', function () {
    Account::create([
        'user_id' => $this->user->id,
        'connector' => ConnectorType::Telegram,
        'account' => 'dm-user-99',
    ]);

    $msg = new IncomingMessage(text: 'hi', connector: ConnectorType::Telegram, key: 'dm-user-99', isDirectMessage: true);
    $thread = Thread::forMessage($msg);

    $user = $thread->user();

    expect($user->getAuthIdentifier())->toBe($this->user->id);
});

it('returns the authenticated request user for API threads', function () {
    // API auth tokens live on an Account row keyed by hash(token), not by the
    // thread key (which is the conversation UUID). Thread::user() must resolve
    // the user via the authenticated request, otherwise every API call 404s.
    $msg = new IncomingMessage(
        text: 'hi',
        connector: ConnectorType::Api,
        key: 'conversation-uuid-not-an-account',
        isDirectMessage: true,
    );
    $thread = Thread::forMessage($msg);

    request()->setUserResolver(fn () => $this->user);

    expect($thread->user()->getAuthIdentifier())->toBe($this->user->id);
});
