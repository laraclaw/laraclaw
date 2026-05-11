<?php

namespace Laraclaw\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Throwable;

/**
 * Agent tool that runs SQL queries through a connection whose grants only allow SELECT.
 */
class ReadDatabase implements Tool
{
    public const string CONNECTION_NAME = 'laraclaw_readonly';

    private const int MAX_OUTPUT_BYTES = 100 * 1024;

    /**
     * Build a readonly connection config from the primary connection and readonly credentials.
     *
     * Returns null when the primary driver is not supported. SQLite ignores the credentials and
     * routes through the laraclaw_sqlite_readonly driver registered by the service provider.
     */
    public static function makeConnectionConfig(array $primary, ?string $username, ?string $password): ?array
    {
        return match ($primary['driver'] ?? null) {
            'mysql', 'mariadb', 'pgsql' => [...$primary, 'username' => $username, 'password' => $password],
            'sqlite' => [...$primary, 'driver' => 'laraclaw_sqlite_readonly'],
            default => null,
        };
    }

    /**
     * Return the tool description shown to the agent.
     */
    public function description(): Stringable|string
    {
        return 'Run a SQL query against the application database. The connection is read-only at the database level, so write statements will be rejected. Returns the rows as JSON.';
    }

    /**
     * Define the input schema for this tool.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->required()->description('A single SQL SELECT statement to execute'),
        ];
    }

    /**
     * Stream the query through the readonly connection, budget bytes, and return JSON.
     */
    public function handle(Request $request): Stringable|string
    {
        $query = trim((string) ($request['query'] ?? ''));

        if ($query === '') {
            return 'The "query" parameter is required and cannot be empty.';
        }

        $connection = $this->connection();
        $this->applyStatementTimeout($connection);

        try {
            $rows = [];
            $bytes = 2;
            $truncated = false;

            foreach ($connection->cursor(rtrim($query, "; \t\n\r\0\x0B")) as $row) {
                $rowArray = (array) $row;
                $rowBytes = strlen((string) json_encode($rowArray, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));

                if ($bytes + $rowBytes + ($rows === [] ? 0 : 1) > self::MAX_OUTPUT_BYTES) {
                    $truncated = true;
                    break;
                }

                $rows[] = $rowArray;
                $bytes += $rowBytes + ($rows === [] ? 0 : 1);
            }
        } catch (Throwable $e) {
            return (string) json_encode(['error' => $e->getMessage()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        $payload = $truncated
            ? ['rows' => $rows, 'truncated' => true, 'note' => 'Result set exceeded 100KB; remaining rows were omitted.']
            : $rows;

        return (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Cap how long a single query can run. SQLite has no equivalent and is left to PHP's time limit.
     */
    private function applyStatementTimeout(ConnectionInterface $connection): void
    {
        $seconds = (int) config('laraclaw.tools.read_database.timeout_seconds', 10);

        if ($seconds <= 0) {
            return;
        }

        try {
            match ($connection->getDriverName()) {
                'mysql', 'mariadb' => $connection->statement('SET SESSION MAX_EXECUTION_TIME = ' . ($seconds * 1000)),
                'pgsql' => $connection->statement("SET statement_timeout = '{$seconds}s'"),
                default => null,
            };
        } catch (Throwable) {
            // A failed timeout-set should not block the query.
        }
    }

    private function connection(): ConnectionInterface
    {
        return DB::connection(self::CONNECTION_NAME);
    }
}
