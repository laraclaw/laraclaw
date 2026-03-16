<?php

use LaraClaw\DTOs\IncomingMessage;
use LaraClaw\Enums\ConnectorType;
use LaraClaw\Enums\MemorySourceType;
use LaraClaw\Models\Embedding;
use LaraClaw\Models\Thread;
use LaraClaw\Services\Memory\EmbedContent;
use Laravel\Ai\Embeddings;

beforeEach(function () {
    $this->user = $this->createUser();
    config(['laraclaw.auth.admin_user_id' => $this->user->id]);

    Embeddings::fake();

    $this->thread = Thread::create(['connector' => 'terminal', 'key' => 'test']);
    $this->message = new IncomingMessage(
        text: 'What is Laravel?',
        connector: ConnectorType::Terminal,
        key: 'test',
    );
});

it('embeds a user message and assistant response', function () {
    resolve(EmbedContent::class)->run(
        thread: $this->thread,
        message: $this->message,
        responseText: 'Laravel is a PHP framework.',
        conversationId: 'conv-123',
    );

    expect(Embedding::count())->toBe(2);

    $userEmbed = Embedding::where('source_type', MemorySourceType::Message)->first();
    expect($userEmbed->content)->toBe('What is Laravel?');
    expect($userEmbed->user_id)->toBe($this->user->id);
    expect($userEmbed->metadata['role'])->toBe('user');

    $assistantEmbed = Embedding::where('source_type', MemorySourceType::Response)->first();
    expect($assistantEmbed->content)->toBe('Laravel is a PHP framework.');
    expect($assistantEmbed->metadata['role'])->toBe('assistant');
});

it('skips empty content', function () {
    $message = new IncomingMessage(
        text: '',
        connector: ConnectorType::Terminal,
        key: 'test',
    );

    resolve(EmbedContent::class)->run(
        thread: $this->thread,
        message: $message,
        responseText: '',
    );

    expect(Embedding::count())->toBe(0);
});

it('deduplicates identical content', function () {
    $embedder = resolve(EmbedContent::class);

    $embedder->run(thread: $this->thread, message: $this->message, responseText: 'Hi there');
    $embedder->run(thread: $this->thread, message: $this->message, responseText: 'Hi there');

    expect(Embedding::count())->toBe(2);
});

it('stores the conversation_id as source_id', function () {
    resolve(EmbedContent::class)->run(
        thread: $this->thread,
        message: $this->message,
        responseText: 'Hi',
        conversationId: 'conv-abc',
    );

    expect(Embedding::first()->source_id)->toBe('conv-abc');
});
