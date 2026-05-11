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

    private const int MAX_ROWS = 500;

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

        try {
            $connection = $this->connection();
            $this->prepareConnection($connection);

            $rows = [];
            $truncated = false;

            foreach ($connection->cursor(rtrim($query, "; \t\n\r\0\x0B")) as $row) {
                if (count($rows) >= self::MAX_ROWS) {
                    $truncated = true;
                    break;
                }

                $rows[] = (array) $row;
            }
        } catch (Throwable $e) {
            return (string) json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_SLASHES);
        }

        $payload = $truncated
            ? ['rows' => $rows, 'truncated' => true, 'note' => 'Result exceeded ' . self::MAX_ROWS . ' rows; add LIMIT or refine the query to see the rest.']
            : $rows;

        return (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Re-assert read-only invariants and apply per-driver query timeouts before each query.
     *
     * SQLite's query_only is a mutable per-connection PRAGMA, so an agent could disable it in one
     * call and write in the next unless we set it again here.
     */
    private function prepareConnection(ConnectionInterface $connection): void
    {
        if ($connection->getDriverName() === 'sqlite') {
            $connection->statement('PRAGMA query_only = ON');
        }

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
            // A failed timeout setting should not block the query.
        }
    }

    private function connection(): ConnectionInterface
    {
        return DB::connection(self::CONNECTION_NAME);
    }
}
