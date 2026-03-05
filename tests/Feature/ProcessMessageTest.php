<?php

use Illuminate\Support\Facades\Cache;
use LaraClaw\Agents\ChatBotAgent;
use LaraClaw\DTOs\Attachment;
use LaraClaw\Jobs\ProcessMessage;
use LaraClaw\Message;
use LaraClaw\Models\Conversation;
use LaraClaw\Models\UserAccount;
use LaraClaw\Tests\Fixtures\FakeChannel;
use Laravel\Ai\Transcription;

// ── DM path ─────────────────────────────────────────────────────────────────

it('processes a DM and delivers the agent reply', function () {
    ChatBotAgent::fake(['Hello back!']);

    $user = $this->createUser();
    $channel = new FakeChannel;

    UserAccount::create([
        'user_id' => $user->id,
        'channel' => 'telegram',
        'account' => 'user-123',
    ]);

    $message = new Message(
        channel: $channel,
        conversationKey: 'user-123',
        conversationIsDirectMessage: true,
        text: 'Hello!',
    );

    app()->call([new ProcessMessage($message), 'handle']);

    ChatBotAgent::assertPrompted('Hello!');
    expect($channel->sent)->toContain('Hello back!');
});

it('creates a Conversation record for a new DM', function () {
    ChatBotAgent::fake(['ok']);

    $user = $this->createUser();
    $channel = new FakeChannel;

    UserAccount::create([
        'user_id' => $user->id,
        'channel' => 'telegram',
        'account' => 'user-456',
    ]);

    $message = new Message(
        channel: $channel,
        conversationKey: 'user-456',
        conversationIsDirectMessage: true,
        text: 'First message',
    );

    app()->call([new ProcessMessage($message), 'handle']);

    expect(Conversation::where('channel', 'telegram')->where('key', 'user-456')->exists())->toBeTrue();
});

// ── Group path ──────────────────────────────────────────────────────────────

it('processes a group message using the owner user', function () {
    ChatBotAgent::fake(['Group reply!']);

    $user = $this->createUser();
    config(['laraclaw.auth.admin_user_id' => $user->id]);

    $channel = new FakeChannel;

    $message = new Message(
        channel: $channel,
        conversationKey: 'group-channel-001',
        conversationIsDirectMessage: false,
        text: 'Hey bot',
    );

    app()->call([new ProcessMessage($message), 'handle']);

    expect($channel->sent)->toContain('Group reply!');
    expect(Conversation::where('key', 'group-channel-001')->exists())->toBeTrue();
});

it('silently returns when the owner user is not configured for group messages', function () {
    config(['laraclaw.auth.admin_user_id' => null]);

    $channel = new FakeChannel;

    $message = new Message(
        channel: $channel,
        conversationKey: 'group-channel-002',
        conversationIsDirectMessage: false,
        text: 'Hey bot',
    );

    app()->call([new ProcessMessage($message), 'handle']);

    expect($channel->sent)->toBeEmpty();
});

// ── !new command ─────────────────────────────────────────────────────────────

it('starts a fresh conversation when the new_conversation cache flag is set', function () {
    ChatBotAgent::fake(['Fresh start!']);

    $user = $this->createUser();
    $channel = new FakeChannel;

    UserAccount::create([
        'user_id' => $user->id,
        'channel' => 'telegram',
        'account' => 'user-new',
    ]);

    Conversation::create([
        'channel' => 'telegram',
        'key' => 'user-new',
        'conversation_id' => 'old-convo-id',
    ]);

    // Simulate the !new command having run and set the cache flag
    Cache::put('new_conversation:telegram:user-new', true);

    $message = new Message(
        channel: $channel,
        conversationKey: 'user-new',
        conversationIsDirectMessage: true,
        text: 'Start over',
    );

    app()->call([new ProcessMessage($message), 'handle']);

    // Cache flag should be consumed
    expect(Cache::has('new_conversation:telegram:user-new'))->toBeFalse();
    expect($channel->sent)->toContain('Fresh start!');
});

// ── Attachments ──────────────────────────────────────────────────────────────

it('appends attachment metadata to the prompt text', function () {
    ChatBotAgent::fake(['Got it!']);

    $user = $this->createUser();
    $channel = new FakeChannel;

    UserAccount::create([
        'user_id' => $user->id,
        'channel' => 'telegram',
        'account' => 'user-att',
    ]);

    $attachment = new Attachment(
        path: 'attachments/photo.jpg',
        disk: 'local',
        mimeType: 'image/jpeg',
        filename: 'photo.jpg',
    );

    $message = new Message(
        channel: $channel,
        conversationKey: 'user-att',
        conversationIsDirectMessage: true,
        text: 'See attached',
        attachments: collect([$attachment]),
    );

    app()->call([new ProcessMessage($message), 'handle']);

    ChatBotAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'photo.jpg'));
});

it('transcribes audio when the message has no text', function () {
    Transcription::fake(['Hello from audio']);
    ChatBotAgent::fake(['Got it!']);

    $user = $this->createUser();
    $channel = new FakeChannel;

    UserAccount::create([
        'user_id' => $user->id,
        'channel' => 'telegram',
        'account' => 'audio-user',
    ]);

    $message = new Message(
        channel: $channel,
        conversationKey: 'audio-user',
        conversationIsDirectMessage: true,
        text: null,
        attachments: collect([
            new Attachment(
                path: 'attachments/voice.ogg',
                disk: 'local',
                mimeType: 'audio/ogg',
                filename: 'voice.ogg',
            ),
        ]),
    );

    app()->call([new ProcessMessage($message), 'handle']);

    Transcription::assertGenerated(fn ($prompt) => $prompt->audio->path === 'attachments/voice.ogg');
    expect($channel->sent)->toContain('Got it!');
});
