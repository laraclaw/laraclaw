<?php

use LaraClaw\Enums\ChannelType;
use LaraClaw\Models\UserAccount;

it('scopes queries by channel and account', function () {
    $user = $this->createUser();

    UserAccount::create(['user_id' => $user->id, 'channel' => ChannelType::Slack, 'account' => 'U123']);
    UserAccount::create(['user_id' => $user->id, 'channel' => ChannelType::Telegram, 'account' => '456']);

    $found = UserAccount::query()->forChannel('U123', ChannelType::Slack)->first();

    expect($found)->not->toBeNull()
        ->and($found->account)->toBe('U123')
        ->and($found->channel)->toBe(ChannelType::Slack);
});

it('returns the owning user via the relation', function () {
    $user = $this->createUser();

    $account = UserAccount::create(['user_id' => $user->id, 'channel' => ChannelType::Email, 'account' => 'me@example.com']);

    expect($account->user->getAuthIdentifier())->toBe($user->id);
});
