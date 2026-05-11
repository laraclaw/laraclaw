<?php

namespace Laraclaw\Database;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laraclaw\Tools\ReadDatabase;
use Throwable;

/**
 * Renders a cached, plain-text description of the readonly database's tables for the agent prompt.
 *
 * Extracted from the Read Database tool so the agent can ask for the schema without depending on
 * the tool itself, and so callers go through the container (Cache::forget after migrations to refresh).
 */
class ReadOnlySchemaSnapshot
{
    private const string CACHE_KEY = 'laraclaw:read_database:schema';

    private const int CACHE_TTL = 3600;

    /**
     * Return a cached text snapshot of every table on the readonly connection.
     */
    public function render(): string
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function (): string {
            try {
                $tables = collect($this->connection()->getSchemaBuilder()->getTables())
                    ->pluck('name')
                    ->sort()
                    ->values();
            } catch (Throwable) {
                return '';
            }

            return $tables
                ->map(fn (string $name): string => $this->renderTable($name))
                ->filter()
                ->implode("\n\n");
        });
    }

    /**
     * Render a single table as a header followed by its columns and foreign keys.
     */
    private function renderTable(string $table): string
    {
        try {
            $schemaBuilder = $this->connection()->getSchemaBuilder();

            $columns = collect($schemaBuilder->getColumns($table))
                ->map(fn (array $c): string => sprintf(
                    '  - %s: %s%s%s',
                    $c['name'],
                    $c['type_name'] ?? $c['type'],
                    $c['nullable'] ? ' nullable' : '',
                    $c['auto_increment'] ? ' auto_increment' : '',
                ));

            $foreignKeys = collect($schemaBuilder->getForeignKeys($table))
                ->map(fn (array $fk): string => sprintf(
                    '  -> %s references %s(%s)',
                    implode(',', $fk['columns']),
                    $fk['foreign_table'],
                    implode(',', $fk['foreign_columns']),
                ));
        } catch (Throwable) {
            return '';
        }

        return $columns->merge($foreignKeys)
            ->prepend($table)
            ->implode("\n");
    }

    private function connection(): ConnectionInterface
    {
        return DB::connection(ReadDatabase::CONNECTION_NAME);
    }
}
