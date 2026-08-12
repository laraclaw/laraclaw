<?php

use Laraclaw\DTOs\Attachment;
use Laraclaw\DTOs\IncomingMessage;
use Laraclaw\Enums\ConnectorType;

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

it('keeps audio out of the model input but still names it in the text', function () {
    // A voice note handed to a text provider as a document fails the whole
    // request with a 400, which is how this surfaced in production.
    $voice = new Attachment(path: 'inbound/uuid/voice.oga', disk: 'local', mimeType: 'audio/ogg', filename: 'voice.oga');
    $msg = new IncomingMessage(text: null, connector: ConnectorType::Telegram, key: '123', attachments: [$voice]);

    [$text, $files] = $msg->toAgentInput();

    expect($files)->toBeEmpty()
        ->and($text)->toContain('voice.oga')
        ->and($text)->toContain('inbound/uuid/voice.oga');
});

it('keeps non audio attachments when an audio file is alongside them', function () {
    $voice = new Attachment(path: 'inbound/uuid/voice.oga', disk: 'local', mimeType: 'audio/ogg', filename: 'voice.oga');
    $photo = new Attachment(path: 'inbound/uuid/photo.jpg', disk: 'local', mimeType: 'image/jpeg', filename: 'photo.jpg');
    $msg = new IncomingMessage(text: 'look', connector: ConnectorType::Telegram, key: '123', attachments: [$voice, $photo]);

    [, $files] = $msg->toAgentInput();

    expect($files)->toHaveCount(1)
        ->and(array_keys($files))->toBe([0]);
});

it('survives serialization and unserialization', function () {
    $msg = new IncomingMessage(text: 'test', connector: ConnectorType::Email, key: 'abc', uuid: 'keep-me');

    $restored = unserialize(serialize($msg));

    expect($restored->uuid)->toBe('keep-me')
        ->and($restored->text)->toBe('test')
        ->and($restored->connector)->toBe(ConnectorType::Email);
});
