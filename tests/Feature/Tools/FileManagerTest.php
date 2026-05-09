<?php

use Illuminate\Support\Facades\Storage;
use Laraclaw\DTOs\IncomingMessage;
use Laraclaw\Enums\ConnectorType;
use Laraclaw\Services\Attachments;
use Laraclaw\Tools\FileManager;
use Laravel\Ai\Tools\Request;

function fileRequest(array $data): Request
{
    $mock = Mockery::mock(Request::class);
    $mock->allows('offsetGet')->andReturnUsing(fn ($key) => $data[$key] ?? null);
    $mock->allows('offsetExists')->andReturnUsing(fn ($key) => array_key_exists($key, $data));
    $mock->allows('toArray')->andReturn($data);

    return $mock;
}

function fileTool(?IncomingMessage $message = null): FileManager
{
    $message ??= new IncomingMessage(
        text: 'test',
        connector: ConnectorType::Terminal,
        key: 'user',
        isDirectMessage: true,
        uuid: 'msg-uuid',
    );

    return new FileManager($message, app(Attachments::class));
}

beforeEach(function () {
    Storage::fake('workspace');
    Storage::fake('attachments');

    config([
        'laraclaw.filesystem.allowed_disks' => ['workspace'],
        'laraclaw.filesystem.attachments_disk' => 'attachments',
        'laraclaw.filesystem.incoming_attachments_path' => 'inbound',
        'laraclaw.filesystem.outgoing_attachments_path' => 'outbound',
    ]);
});

it('rejects disks not on the allowlist', function () {
    $result = fileTool()->handle(fileRequest([
        'operation' => 'list',
        'disk' => 'private',
        'path' => '/',
    ]));

    expect($result)->toContain("Disk 'private' is not allowed");
});

it('blocks path traversal in the primary path', function () {
    $result = fileTool()->handle(fileRequest([
        'operation' => 'read',
        'disk' => 'workspace',
        'path' => '../../etc/passwd',
    ]));

    expect($result)->toBe('Path traversal is not allowed.');
});

it('blocks path traversal in the destination path', function () {
    Storage::disk('workspace')->put('a.txt', 'hello');

    $result = fileTool()->handle(fileRequest([
        'operation' => 'move',
        'disk' => 'workspace',
        'path' => 'a.txt',
        'destination' => '../escape.txt',
    ]));

    expect($result)->toBe('Path traversal is not allowed.');
});

it('writes a file then reads it back', function () {
    fileTool()->handle(fileRequest([
        'operation' => 'write',
        'disk' => 'workspace',
        'path' => 'note.md',
        'content' => 'first line',
    ]));

    expect(Storage::disk('workspace')->get('note.md'))->toBe('first line');

    $read = fileTool()->handle(fileRequest([
        'operation' => 'read',
        'disk' => 'workspace',
        'path' => 'note.md',
    ]));

    expect($read)->toBe('first line');
});

it('appends to an existing file', function () {
    Storage::disk('workspace')->put('log.txt', "line one\n");

    fileTool()->handle(fileRequest([
        'operation' => 'append',
        'disk' => 'workspace',
        'path' => 'log.txt',
        'content' => 'line two',
    ]));

    expect(Storage::disk('workspace')->get('log.txt'))->toContain('line one')->toContain('line two');
});

it('refuses to write without content', function () {
    $result = fileTool()->handle(fileRequest([
        'operation' => 'write',
        'disk' => 'workspace',
        'path' => 'note.md',
    ]));

    expect($result)->toContain('"content" parameter is required');
});

it('returns a not-found message when reading a missing file', function () {
    $result = fileTool()->handle(fileRequest([
        'operation' => 'read',
        'disk' => 'workspace',
        'path' => 'missing.txt',
    ]));

    expect($result)->toContain('File not found');
});

it('lists files and directories at a path', function () {
    Storage::disk('workspace')->put('a.txt', 'x');
    Storage::disk('workspace')->put('b.txt', 'y');
    Storage::disk('workspace')->makeDirectory('sub');

    $result = fileTool()->handle(fileRequest([
        'operation' => 'list',
        'disk' => 'workspace',
        'path' => '/',
    ]));

    expect($result)->toContain('a.txt')->toContain('b.txt')->toContain('sub');
});

it('does not list the protected attachments directories', function () {
    Storage::disk('workspace')->put('inbound/leaked.txt', 'secret');

    $result = fileTool()->handle(fileRequest([
        'operation' => 'list',
        'disk' => 'workspace',
        'path' => '/',
    ]));

    expect($result)->not->toContain('inbound');
});

it('refuses to read inside the protected attachments directory via list', function () {
    $result = fileTool()->handle(fileRequest([
        'operation' => 'list',
        'disk' => 'workspace',
        'path' => 'inbound',
    ]));

    expect($result)->toBe("Cannot list system directory 'inbound'.");
});

it('reports file existence', function () {
    Storage::disk('workspace')->put('there.txt', '.');

    expect(fileTool()->handle(fileRequest([
        'operation' => 'exists',
        'disk' => 'workspace',
        'path' => 'there.txt',
    ])))->toContain('File exists');

    expect(fileTool()->handle(fileRequest([
        'operation' => 'exists',
        'disk' => 'workspace',
        'path' => 'nope.txt',
    ])))->toContain('does not exist');
});

it('moves a file to a new path', function () {
    Storage::disk('workspace')->put('from.txt', '.');

    fileTool()->handle(fileRequest([
        'operation' => 'move',
        'disk' => 'workspace',
        'path' => 'from.txt',
        'destination' => 'to.txt',
    ]));

    expect(Storage::disk('workspace')->exists('from.txt'))->toBeFalse();
    expect(Storage::disk('workspace')->exists('to.txt'))->toBeTrue();
});

it('renames the destination of a move when the target is taken', function () {
    Storage::disk('workspace')->put('from.txt', 'a');
    Storage::disk('workspace')->put('to.txt', 'b');

    $result = fileTool()->handle(fileRequest([
        'operation' => 'move',
        'disk' => 'workspace',
        'path' => 'from.txt',
        'destination' => 'to.txt',
    ]));

    expect($result)->toContain("'to.txt' was taken");
    expect(Storage::disk('workspace')->get('to.txt'))->toBe('b');
});

it('copies a file to a new path', function () {
    Storage::disk('workspace')->put('source.txt', 'data');

    fileTool()->handle(fileRequest([
        'operation' => 'copy',
        'disk' => 'workspace',
        'path' => 'source.txt',
        'destination' => 'copy.txt',
    ]));

    expect(Storage::disk('workspace')->get('source.txt'))->toBe('data');
    expect(Storage::disk('workspace')->get('copy.txt'))->toBe('data');
});

it('creates a directory', function () {
    fileTool()->handle(fileRequest([
        'operation' => 'mkdir',
        'disk' => 'workspace',
        'path' => 'new-dir',
    ]));

    expect(Storage::disk('workspace')->directoryExists('new-dir'))->toBeTrue();
});

it('saves an attachment from the attachments disk to the working disk', function () {
    Storage::disk('attachments')->put('inbound/photo.png', 'imgdata');

    fileTool()->handle(fileRequest([
        'operation' => 'save_attachment',
        'disk' => 'workspace',
        'path' => 'photos/photo.png',
        'source' => 'inbound/photo.png',
    ]));

    expect(Storage::disk('workspace')->get('photos/photo.png'))->toBe('imgdata');
});

it('refuses save_attachment when the source is missing', function () {
    $result = fileTool()->handle(fileRequest([
        'operation' => 'save_attachment',
        'disk' => 'workspace',
        'path' => 'photo.png',
        'source' => 'inbound/missing.png',
    ]));

    expect($result)->toContain('Attachment not found');
});

it('queues a file for outbound attachment when calling attach_to_reply', function () {
    Storage::disk('workspace')->put('report.txt', 'final');

    $result = fileTool()->handle(fileRequest([
        'operation' => 'attach_to_reply',
        'disk' => 'workspace',
        'path' => 'report.txt',
    ]));

    expect($result)->toContain('will be attached');
    expect(Storage::disk('attachments')->exists('outbound/msg-uuid/report.txt'))->toBeTrue();
});
