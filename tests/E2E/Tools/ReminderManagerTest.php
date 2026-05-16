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
    // Anchor on a specific UTC instant in the prompt so neither the model's
    // timezone inference nor the app timezone can shift the stored value.
    $when = now('UTC')->addDay()->setTime(9, 0);

    $first = $this->postMessage(
        "Set a one-off reminder for {$when->format('Y-m-d')} at 09:00 UTC that says: Stand-up meeting."
    );
    expect($first['success'])->toBeTrue();

    $second = $this->postMessage('Use email please.', $first['key']);
    expect($second['success'])->toBeTrue();

    $reminder = Reminder::query()
        ->where('user_id', config('laraclaw.auth.admin_user_id'))
        ->where('message', 'like', '%stand-up%')
        ->latest('id')
        ->first();

    expect($reminder)->not->toBeNull();
    expect($reminder->connector)->toBe(ConnectorType::Email);
    expect($reminder->remind_at->utc()->format('Y-m-d H:i'))->toBe($when->format('Y-m-d H:i'));
});
