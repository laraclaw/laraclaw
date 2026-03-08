<?php

use LaraClaw\Commands\Command;
use LaraClaw\Commands\CommandRegistry;

it('matches a registered command case-insensitively', function () {
    $registry = new CommandRegistry;
    $command = Mockery::mock(Command::class);
    $command->allows('trigger')->andReturn('!ping');

    $registry->register($command);

    expect($registry->match('!ping'))->toBe($command)
        ->and($registry->match('!PING'))->toBe($command)
        ->and($registry->match('  !ping  '))->toBe($command);
});

it('returns null when no command matches', function () {
    $registry = new CommandRegistry;

    expect($registry->match('!unknown'))->toBeNull();
});
