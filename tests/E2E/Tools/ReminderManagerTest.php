<?php

use Laraclaw\Enums\ConnectorType;
use Laraclaw\Models\Account;
use Laraclaw\Models\Reminder;

beforeEach(function (): void {
    $this->requireEnv('ANTHROPIC_API_KEY');
    $user = $this->authenticatedUser();

    Account::create([
        'user_id' => $user->getAuthIdentifier(),
        'connector' => ConnectorType::Email,
        'account' => 'reminders@example.com',
    ]);
});

it('creates a real reminder row over a two-turn conversation', function (): void {
    $first = $this->postMessage('Set a one-off reminder for tomorrow at 9:00 AM that says: Stand-up meeting.');
    expect($first['success'])->toBeTrue();

    $second = $this->postMessage('Use email please.', $first['key']);
    expect($second['success'])->toBeTrue();

    $reminder = Reminder::query()->latest('id')->first();
    expect($reminder)->not->toBeNull();
    expect($reminder->connector)->toBe(ConnectorType::Email);
    expect(strtolower($reminder->message))->toContain('stand-up');
    expect($reminder->remind_at->format('H:i'))->toBe('09:00');
});
