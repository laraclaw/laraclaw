<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laraclaw\Tests\Fixtures\UuidUser;

use function Laraclaw\Support\userForeignKey;

/**
 * MySQL is the only driver that enforces collation compatibility across a foreign
 * key, so this case cannot be proven on the SQLite the rest of the suite uses. The
 * test skips itself when no MySQL is reachable.
 */
beforeEach(function () {
    config([
        'database.connections.mysql_probe' => [
            'driver' => 'mysql',
            'host' => getenv('DB_HOST') ?: '127.0.0.1',
            'port' => getenv('DB_PORT') ?: '3306',
            'database' => 'laraclaw_collation_probe',
            'username' => getenv('DB_USERNAME') ?: 'root',
            'password' => getenv('DB_PASSWORD') ?: '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_0900_ai_ci',
        ],
    ]);

    // Connect with no database selected first, since the probe schema may not exist yet.
    config(['database.connections.mysql_probe_root' => array_merge(
        config('database.connections.mysql_probe'),
        ['database' => null],
    )]);

    try {
        DB::connection('mysql_probe_root')->statement('create database if not exists laraclaw_collation_probe');
    } catch (Throwable $e) {
        $this->markTestSkipped('No MySQL available: ' . $e->getMessage());
    }

    config(['database.default' => 'mysql_probe']);

    Schema::dropIfExists('probe_children');
    Schema::dropIfExists('members');
});

afterEach(function () {
    if (config('database.default') !== 'mysql_probe') {
        return;
    }

    Schema::dropIfExists('probe_children');
    Schema::dropIfExists('members');

    // Hand the suite back its SQLite connection, or Testbench rolls its migrations
    // back against MySQL on the way out.
    config(['database.default' => 'testing']);
});

it('matches the collation of the referenced user key so mysql accepts the foreign key', function () {
    // A users table whose key collation is not the connection default. Without
    // copying that collation onto the referencing column MySQL rejects the
    // constraint outright with errno 3780.
    Schema::create('members', function (Blueprint $table) {
        $table->char('uuid', 36)->collation('utf8mb4_bin')->primary();
    });

    config(['laraclaw.auth.user_model' => UuidUser::class]);

    Schema::create('probe_children', function (Blueprint $table) {
        $table->id();
        userForeignKey($table)->cascadeOnDelete();
    });

    expect(Schema::hasColumn('probe_children', 'user_id'))->toBeTrue();

    $collation = collect(Schema::getColumns('probe_children'))
        ->firstWhere('name', 'user_id')['collation'];

    expect($collation)->toBe('utf8mb4_bin');
});
