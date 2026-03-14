<?php

use LaraClaw\DTOs\Attachment;
use LaraClaw\DTOs\IncomingMessage;
use LaraClaw\Enums\ConnectorType;

it('generates a uuid when none is provided', function () {
    $msg = new IncomingMessage(text: 'hi', connector: ConnectorType::Telegram, key: '123');

    expect($msg->uuid)->toBeString()->not->toBeEmpty();
});

it('uses the provided uuid', function () {
    $msg = new IncomingMessage(text: 'hi', connector: ConnectorType::Telegram, key: '123', uuid: 'custom-uuid');

    expect($msg->uuid)->toBe('custom-uuid');
});

it('returns text only when there are no attachments', function () {
    $msg = new IncomingMessage(text: 'hello', connector: ConnectorType::Slack, key: '456');
    [$text, $files] = $msg->toAgentInput();

    expect($text)->toBe('hello')
        ->and($files)->toBeEmpty();
});

it('appends attachment metadata to text', function () {
    $attachment = new Attachment(path: 'inbound/uuid/photo.jpg', disk: 'local', mimeType: 'image/jpeg', filename: 'photo.jpg');
    $msg = new IncomingMessage(text: 'check this', connector: ConnectorType::Slack, key: '456', attachments: [$attachment]);
    [$text, $files] = $msg->toAgentInput();

    expect($text)->toContain('photo.jpg')
        ->and($text)->toContain('image/jpeg')
        ->and($files)->toHaveCount(1);
});

it('survives serialization and unserialization', function () {
    $msg = new IncomingMessage(text: 'test', connector: ConnectorType::Email, key: 'abc', uuid: 'keep-me');

    $restored = unserialize(serialize($msg));

    expect($restored->uuid)->toBe('keep-me')
        ->and($restored->text)->toBe('test')
        ->and($restored->connector)->toBe(ConnectorType::Email);
});
