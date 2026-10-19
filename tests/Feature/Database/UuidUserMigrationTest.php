<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laraclaw\Enums\ConnectorType;
use Laraclaw\Models\Account;
use Laraclaw\Tests\Fixtures\UuidUser;

beforeEach(function () {
    Schema::create('members', function (Blueprint $table) {
        $table->uuid('uuid')->primary();
        $table->string('name')->default('Test Member');
        $table->string('email')->unique();
        $table->timestamps();
    });

    config(['laraclaw.auth.user_model' => UuidUser::class]);

    // The suite already migrated against the default bigint user, so roll the
    // package tables back and build them again now that the configured model is
    // the one keyed by a UUID.
    $this->artisan('migrate:refresh');
});

it('points every user column at the configured user table and key', function (string $table) {
    expect(Schema::hasColumn($table, 'user_id'))->toBeTrue();

    $foreignKey = collect(DB::select('pragma foreign_key_list(' . $table . ')'))
        ->firstWhere('from', 'user_id');

    expect($foreignKey)->not->toBeNull()
        ->and($foreignKey->table)->toBe('members')
        ->and($foreignKey->to)->toBe('uuid');
})->with([
    'laraclaw_accounts',
    'laraclaw_reminders',
    'laraclaw_routines',
    'laraclaw_embeddings',
]);

it('gives the user column a string type rather than an integer one', function () {
    expect(Schema::getColumnType('laraclaw_accounts', 'user_id'))->not->toContain('int');
});

it('stores and reads back a record owned by a uuid keyed user', function () {
    $user = UuidUser::create(['email' => 'member@example.com']);

    $account = Account::create([
        'user_id' => $user->uuid,
        'connector' => ConnectorType::Slack,
        'account' => 'U123',
    ]);

    expect($account->user->uuid)->toBe($user->uuid);
});
