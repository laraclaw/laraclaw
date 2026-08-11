<?php

use Laraclaw\DTOs\IncomingMessage;
use Laraclaw\Enums\ConnectorType;
use Laraclaw\Services\Attachments;
use Laraclaw\Tools\FileManager;
use Laravel\Ai\Contracts\Approvable;
use Laravel\Ai\Tools\Request;

function gatedMessage(): IncomingMessage
{
    return new IncomingMessage(
        text: 'do it',
        connector: ConnectorType::Telegram,
        key: '12345',
        isDirectMessage: true,
    );
}

function gatedFileTool(): FileManager
{
    return new FileManager(gatedMessage(), app(Attachments::class));
}

function gatedRequest(array $arguments): Request
{
    return new Request($arguments, 'call_abc');
}

it('exposes gated tools to the SDK as approvable', function () {
    expect(gatedFileTool())->toBeInstanceOf(Approvable::class);
});

it('requires approval for a destructive operation and explains why', function () {
    $approval = gatedFileTool()->shouldRequestApproval(gatedRequest([
        'operation' => 'delete',
        'disk' => 'local',
        'path' => 'a.txt',
    ]));

    expect($approval)->not->toBeNull()
        ->and($approval->reason)->toContain('a.txt')
        ->and($approval->reason)->toContain('Delete');
});

it('does not require approval for a read only operation', function () {
    $approval = gatedFileTool()->shouldRequestApproval(gatedRequest([
        'operation' => 'list',
        'disk' => 'local',
        'path' => '',
    ]));

    expect($approval)->toBeNull();
});

it('lets the agent waive approval on a tool', function () {
    $approval = gatedFileTool()->withoutApproval()->shouldRequestApproval(gatedRequest([
        'operation' => 'delete',
        'disk' => 'local',
        'path' => 'a.txt',
    ]));

    expect($approval)->toBeNull();
});

it('lets the agent force approval on an otherwise ungated operation', function () {
    $approval = gatedFileTool()->requireApproval('Review every file operation')->shouldRequestApproval(gatedRequest([
        'operation' => 'list',
        'disk' => 'local',
        'path' => '',
    ]));

    expect($approval)->not->toBeNull()
        ->and($approval->reason)->toBe('Review every file operation');
});
