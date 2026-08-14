<?php

use function Laraclaw\Support\parseNaturalDate;

beforeEach(function () {
    $this->travelTo('2026-08-13 12:00:00 UTC');
});

it('parses the relative phrasings people actually write', function (string $input, string $expected) {
    expect(parseNaturalDate($input)?->toDateTimeString())->toBe($expected);
})->with([
    // strtotime rejects every one of these on its own, including the phrasing our
    // own tool description advertises.
    ['in 2 minutes', '2026-08-13 12:02:00'],
    ['in 3 hours', '2026-08-13 15:00:00'],
    ['in 1 day', '2026-08-14 12:00:00'],
    ['tomorrow at 10am', '2026-08-14 10:00:00'],
    ['at 5pm', '2026-08-13 17:00:00'],
    ['IN 2 MINUTES', '2026-08-13 12:02:00'],
]);

it('still parses what strtotime already understood', function (string $input, string $expected) {
    expect(parseNaturalDate($input)?->toDateTimeString())->toBe($expected);
})->with([
    ['2026-09-01T08:30:00+00:00', '2026-09-01 08:30:00'],
    ['2026-09-01 08:30:00', '2026-09-01 08:30:00'],
    ['+2 minutes', '2026-08-13 12:02:00'],
    ['tomorrow', '2026-08-14 00:00:00'],
    ['next friday', '2026-08-14 00:00:00'],
]);

it('returns null when the value is not a date', function (string $input) {
    expect(parseNaturalDate($input))->toBeNull();
})->with([
    ['whenever you feel like it'],
    [''],
    ['   '],
]);

it('reads bare times as the application timezone', function (string $input, string $expected) {
    withTimezone('America/Argentina/Buenos_Aires');

    expect(parseNaturalDate($input)?->toDateTimeString())->toBe($expected);
})->with([
    // Test now is 12:00 UTC, which is 09:00 in Buenos Aires.
    ['tomorrow at 10am', '2026-08-14 10:00:00'],
    ['at 5pm', '2026-08-13 17:00:00'],
    ['in 2 minutes', '2026-08-13 09:02:00'],
    ['2026-09-01 08:30:00', '2026-09-01 08:30:00'],
]);

it('converts an explicit offset into the application timezone', function () {
    withTimezone('America/Argentina/Buenos_Aires');

    $parsed = parseNaturalDate('2026-09-01T08:30:00+00:00');

    expect($parsed?->toDateTimeString())->toBe('2026-09-01 05:30:00')
        ->and($parsed?->getTimezone()->getName())->toBe('America/Argentina/Buenos_Aires');
});

it('falls back to UTC when the application has no timezone set', function () {
    config(['app.timezone' => null]);

    expect(parseNaturalDate('at 5pm')?->getTimezone()->getName())->toBe('UTC');
});
