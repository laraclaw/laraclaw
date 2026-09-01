<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laraclaw\Tests\Fixtures\StringKeyUser;
use Laraclaw\Tests\Fixtures\UlidUser;
use Laraclaw\Tests\Fixtures\UuidUser;

use function Laraclaw\Support\userForeignKey;

it('leaves the default user model on a bigint column referencing users.id', function (string $table) {
    $foreignKey = collect(DB::select('pragma foreign_key_list(' . $table . ')'))
        ->firstWhere('from', 'user_id');

    expect($foreignKey)->not->toBeNull()
        ->and($foreignKey->table)->toBe('users')
        ->and($foreignKey->to)->toBe('id')
        ->and(Schema::getColumnType($table, 'user_id'))->toBe('integer');
})->with([
    'laraclaw_accounts',
    'laraclaw_reminders',
    'laraclaw_routines',
    'laraclaw_embeddings',
]);

it('matches the column type to the key type of the configured user model', function (string $model, string $type) {
    config(['laraclaw.auth.user_model' => $model]);

    $blueprint = new Blueprint(Schema::getConnection(), 'examples');
    userForeignKey($blueprint);

    expect($blueprint->getColumns()[0]->get('type'))->toBe($type);
})->with([
    'auto incrementing' => [User::class, 'bigInteger'],
    'uuid' => [UuidUser::class, 'uuid'],
    'ulid' => [UlidUser::class, 'char'],
    'plain string' => [StringKeyUser::class, 'string'],
]);

it('references the table and key name the model reports', function () {
    config(['laraclaw.auth.user_model' => StringKeyUser::class]);

    $blueprint = new Blueprint(Schema::getConnection(), 'examples');
    userForeignKey($blueprint);

    $foreign = collect($blueprint->getCommands())->firstWhere('name', 'foreign');

    expect($foreign->on)->toBe('accounts_people')
        ->and($foreign->references)->toBe('username')
        ->and($foreign->columns)->toBe(['user_id']);
});
