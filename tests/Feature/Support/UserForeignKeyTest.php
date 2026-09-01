<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laraclaw\Tests\Fixtures\StringKeyUser;
use Laraclaw\Tests\Fixtures\UlidUser;
use Laraclaw\Tests\Fixtures\UuidUser;

use function Laraclaw\Support\columnLength;
use function Laraclaw\Support\userForeignKey;
use function Laraclaw\Support\userModel;

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

it('copies the declared length out of a referenced column description', function (?array $column, ?int $expected) {
    expect(columnLength($column))->toBe($expected);
})->with([
    'mysql varchar' => [['type' => 'varchar(50)'], 50],
    'mysql char' => [['type' => 'char(36)'], 36],
    'sqlite reports no width' => [['type' => 'varchar'], null],
    'no referenced table yet' => [null, null],
]);

it('falls back to the auth provider model when no laraclaw model is set', function () {
    config(['laraclaw.auth.user_model' => null]);
    config(['auth.providers.users.model' => UuidUser::class]);

    expect(userModel())->toBeInstanceOf(UuidUser::class);
});

it('rejects a user model class that does not exist', function () {
    config(['laraclaw.auth.user_model' => 'App\\Models\\NotHere']);

    userModel();
})->throws(InvalidArgumentException::class, 'does not exist');

it('rejects a user model that is not an eloquent model', function () {
    config(['laraclaw.auth.user_model' => DateTime::class]);

    userModel();
})->throws(InvalidArgumentException::class, 'not an Eloquent model');
