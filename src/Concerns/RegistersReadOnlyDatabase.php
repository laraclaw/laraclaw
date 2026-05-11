<?php

namespace Laraclaw\Concerns;

use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Laraclaw\Tools\ReadDatabase;
use PDO;

/**
 * Wires up the `laraclaw_readonly` database connection used by the Read Database tool.
 *
 * For MySQL and Postgres the connection mirrors the primary one but swaps in the readonly
 * credentials collected by the wizard. For SQLite the same file is opened in readonly mode
 * through a one-off driver, since Laravel's stock SQLite connector calls realpath() and
 * rejects paths in URI form.
 */
trait RegistersReadOnlyDatabase
{
    /**
     * Register the readonly SQLite driver and inject the `laraclaw_readonly` connection config.
     */
    private function registerReadOnlyDatabaseConnection(): void
    {
        DB::extend('laraclaw_sqlite_readonly', function (array $config): SQLiteConnection {
            $path = realpath($config['database']);

            if ($path === false) {
                throw new InvalidArgumentException("Database ({$config['database']}) does not exist.");
            }

            $pdo = new PDO("sqlite:file:{$path}?mode=ro", null, null, $config['options'] ?? []);

            // mode=ro keeps the main file readonly, but SQLite allows ATTACH DATABASE, which
            // defaults to read-write on the attached file. query_only blocks any write through
            // this connection regardless of how the agent gets at another database.
            $pdo->exec('PRAGMA query_only = ON');

            return new SQLiteConnection($pdo, $config['database'], $config['prefix'] ?? '', $config);
        });

        if (! config('laraclaw.tools.read_database.enabled')) {
            return;
        }

        $default = config('database.default');
        $primary = config("database.connections.{$default}");

        if (! $primary) {
            return;
        }

        $readonly = ReadDatabase::makeConnectionConfig(
            $primary,
            config('laraclaw.tools.read_database.username'),
            config('laraclaw.tools.read_database.password'),
        );

        if ($readonly === null) {
            return;
        }

        config(['database.connections.' . ReadDatabase::CONNECTION_NAME => $readonly]);
    }
}
