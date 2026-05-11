<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laraclaw\Database\ReadOnlySchemaSnapshot;
use Laraclaw\Tools\ReadDatabase;
use Laravel\Ai\Tools\Request;

function readDatabaseRequest(array $data): Request
{
    $mock = Mockery::mock(Request::class);
    $mock->allows('offsetGet')->andReturnUsing(fn ($key) => $data[$key] ?? null);
    $mock->allows('offsetExists')->andReturnUsing(fn ($key) => array_key_exists($key, $data));

    return $mock;
}

beforeEach(function () {
    $this->readonlyDbPath = tempnam(sys_get_temp_dir(), 'laraclaw_ro_') . '.sqlite';
    touch($this->readonlyDbPath);

    config(['database.connections.' . ReadDatabase::CONNECTION_NAME => [
        'driver' => 'sqlite',
        'database' => $this->readonlyDbPath,
        'prefix' => '',
    ]]);

    DB::purge(ReadDatabase::CONNECTION_NAME);
    DB::connection(ReadDatabase::CONNECTION_NAME)->statement(
        'create table users (id integer primary key autoincrement, name text, email text)'
    );

    Cache::forget('laraclaw:read_database:schema');
});

afterEach(function () {
    if (isset($this->readonlyDbPath) && file_exists($this->readonlyDbPath)) {
        @unlink($this->readonlyDbPath);
    }
});

it('runs a SELECT query and returns rows as JSON', function () {
    DB::connection(ReadDatabase::CONNECTION_NAME)->table('users')->insert([
        ['name' => 'Alice', 'email' => 'alice@example.com'],
        ['name' => 'Bob', 'email' => 'bob@example.com'],
    ]);

    $result = (new ReadDatabase)->handle(readDatabaseRequest([
        'query' => 'select name, email from users order by name',
    ]));

    $decoded = json_decode($result, true);

    expect($decoded)->toHaveCount(2);
    expect($decoded[0]['name'])->toBe('Alice');
    expect($decoded[1]['name'])->toBe('Bob');
});

it('strips a trailing semicolon', function () {
    $result = (new ReadDatabase)->handle(readDatabaseRequest([
        'query' => 'select 1 as n;',
    ]));

    expect(json_decode($result, true)[0]['n'])->toBe(1);
});

it('returns an error when query is empty', function () {
    $result = (new ReadDatabase)->handle(readDatabaseRequest(['query' => '']));

    expect($result)->toContain('"query" parameter is required');
});

it('returns an error when query is missing', function () {
    $result = (new ReadDatabase)->handle(readDatabaseRequest([]));

    expect($result)->toContain('"query" parameter is required');
});

it('returns a structured error when the database raises an exception', function () {
    $result = (new ReadDatabase)->handle(readDatabaseRequest([
        'query' => 'select * from nonexistent_table',
    ]));

    expect(json_decode($result, true))->toHaveKey('error');
});

it('exposes a schema snapshot built from the readonly connection', function () {
    $snapshot = (new ReadOnlySchemaSnapshot)->render();

    expect($snapshot)->toContain('users');
    expect($snapshot)->toContain('email');
});

it('returns valid JSON when output is truncated', function () {
    $rows = collect(range(1, 5000))
        ->map(fn (int $i): array => ['name' => 'user-' . str_repeat('x', 50), 'email' => "u{$i}@example.com"])
        ->all();

    DB::connection(ReadDatabase::CONNECTION_NAME)->table('users')->insert($rows);

    $result = (new ReadDatabase)->handle(readDatabaseRequest([
        'query' => 'select name, email from users',
    ]));

    $decoded = json_decode($result, true);

    expect(json_last_error())->toBe(JSON_ERROR_NONE);
    expect($decoded)->toHaveKey('truncated');
    expect($decoded['truncated'])->toBeTrue();
    expect($decoded['rows'])->toBeArray()->not->toBeEmpty();
});

it('encodes binary and invalid UTF-8 columns without failing', function () {
    DB::connection(ReadDatabase::CONNECTION_NAME)
        ->statement("insert into users (name, email) values (x'c3', 'binary@example.com')");

    $result = (new ReadDatabase)->handle(readDatabaseRequest([
        'query' => "select name, email from users where email = 'binary@example.com'",
    ]));

    expect(json_last_error())->toBe(JSON_ERROR_NONE);

    $decoded = json_decode($result, true);
    expect($decoded[0]['email'])->toBe('binary@example.com');
});

it('blocks writes through the readonly SQLite driver', function () {
    $readonlyPath = $this->readonlyDbPath;

    DB::connection(ReadDatabase::CONNECTION_NAME)->table('users')->insert(['name' => 'Original', 'email' => 'o@example.com']);
    DB::purge(ReadDatabase::CONNECTION_NAME);

    config(['database.connections.' . ReadDatabase::CONNECTION_NAME => [
        'driver' => 'laraclaw_sqlite_readonly',
        'database' => $readonlyPath,
        'prefix' => '',
    ]]);

    $result = (new ReadDatabase)->handle(readDatabaseRequest([
        'query' => "update users set name = 'Hacked' where email = 'o@example.com'",
    ]));

    expect(json_decode($result, true))->toHaveKey('error');

    DB::purge(ReadDatabase::CONNECTION_NAME);
    config(['database.connections.' . ReadDatabase::CONNECTION_NAME => [
        'driver' => 'sqlite',
        'database' => $readonlyPath,
        'prefix' => '',
    ]]);

    $stillOriginal = DB::connection(ReadDatabase::CONNECTION_NAME)
        ->table('users')
        ->where('email', 'o@example.com')
        ->value('name');

    expect($stillOriginal)->toBe('Original');
});

it('re-asserts query_only between calls so an agent cannot disable it', function () {
    DB::connection(ReadDatabase::CONNECTION_NAME)->table('users')->insert(['name' => 'Original', 'email' => 'o@example.com']);
    DB::purge(ReadDatabase::CONNECTION_NAME);

    config(['database.connections.' . ReadDatabase::CONNECTION_NAME => [
        'driver' => 'laraclaw_sqlite_readonly',
        'database' => $this->readonlyDbPath,
        'prefix' => '',
    ]]);

    (new ReadDatabase)->handle(readDatabaseRequest([
        'query' => 'PRAGMA query_only = OFF',
    ]));

    $result = (new ReadDatabase)->handle(readDatabaseRequest([
        'query' => "update users set name = 'Hacked' where email = 'o@example.com'",
    ]));

    expect(json_decode($result, true))->toHaveKey('error');

    DB::purge(ReadDatabase::CONNECTION_NAME);
    config(['database.connections.' . ReadDatabase::CONNECTION_NAME => [
        'driver' => 'sqlite',
        'database' => $this->readonlyDbPath,
        'prefix' => '',
    ]]);

    $stillOriginal = DB::connection(ReadDatabase::CONNECTION_NAME)
        ->table('users')
        ->where('email', 'o@example.com')
        ->value('name');

    expect($stillOriginal)->toBe('Original');
});

it('blocks writes to ATTACH-ed databases on SQLite', function () {
    $attachPath = tempnam(sys_get_temp_dir(), 'laraclaw_attach_') . '.sqlite';
    touch($attachPath);

    try {
        config(['database.connections.laraclaw_attach_writable' => [
            'driver' => 'sqlite',
            'database' => $attachPath,
            'prefix' => '',
        ]]);
        DB::connection('laraclaw_attach_writable')->statement(
            'create table secrets (id integer primary key, value text)'
        );
        DB::connection('laraclaw_attach_writable')->statement(
            "insert into secrets (value) values ('original')"
        );
        DB::purge('laraclaw_attach_writable');

        DB::purge(ReadDatabase::CONNECTION_NAME);
        config(['database.connections.' . ReadDatabase::CONNECTION_NAME => [
            'driver' => 'laraclaw_sqlite_readonly',
            'database' => $this->readonlyDbPath,
            'prefix' => '',
        ]]);

        $attach = (new ReadDatabase)->handle(readDatabaseRequest([
            'query' => "attach database '{$attachPath}' as other",
        ]));

        // ATTACH itself is allowed under query_only; what we need to verify is that writes are blocked.
        $update = (new ReadDatabase)->handle(readDatabaseRequest([
            'query' => "update other.secrets set value = 'hacked' where id = 1",
        ]));

        expect(json_decode($update, true))->toHaveKey('error');

        DB::purge(ReadDatabase::CONNECTION_NAME);
        config(['database.connections.' . ReadDatabase::CONNECTION_NAME => [
            'driver' => 'sqlite',
            'database' => $attachPath,
            'prefix' => '',
        ]]);

        $stillOriginal = DB::connection(ReadDatabase::CONNECTION_NAME)
            ->table('secrets')
            ->where('id', 1)
            ->value('value');

        expect($stillOriginal)->toBe('original');
    } finally {
        @unlink($attachPath);
    }
});
