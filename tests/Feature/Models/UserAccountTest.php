<?php

use LaraClaw\Enums\ConnectorType;
use LaraClaw\Models\UserAccount;

it('scopes queries by connector and account', function () {
    $user = $this->createUser();

    UserAccount::create(['user_id' => $user->id, 'connector' => ConnectorType::Slack, 'account' => 'U123']);
    UserAccount::create(['user_id' => $user->id, 'connector' => ConnectorType::Telegram, 'account' => '456']);

    $found = UserAccount::query()->forConnector('U123', ConnectorType::Slack)->first();

    expect($found)->not->toBeNull()
        ->and($found->account)->toBe('U123')
        ->and($found->connector)->toBe(ConnectorType::Slack);
});

it('returns the owning user via the relation', function () {
    $user = $this->createUser();

    $account = UserAccount::create(['user_id' => $user->id, 'connector' => ConnectorType::Email, 'account' => 'me@example.com']);

    expect($account->user->getAuthIdentifier())->toBe($user->id);
});
