<?php

namespace Laraclaw\Services;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laraclaw\Tools\ReadDatabase;
use Throwable;

/**
 * Renders a cached schema description of the readonly database for the agent prompt.
 *
 * SQLite and MySQL expose native CREATE TABLE DDL so we hand that to the agent verbatim.
 * Postgres has no single command equivalent; we fall back to the raw metadata as JSON.
 */
class DatabaseSchemaReader
{
    private const string CACHE_KEY = 'laraclaw:read_database:schema';

    private const int CACHE_TTL = 3600;

    /**
     * Return a cached snapshot of the readonly connection's schema.
     */
    public function render(): string
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function (): string {
            try {
                $connection = $this->connection();

                return match ($connection->getDriverName()) {
                    'sqlite' => $this->renderSqlite($connection),
                    'mysql', 'mariadb' => $this->renderMysql($connection),
                    'pgsql' => $this->renderPgsql($connection),
                    default => '',
                };
            } catch (Throwable) {
                return '';
            }
        });
    }

    /**
     * Dump every CREATE TABLE and CREATE INDEX statement stored by SQLite itself.
     */
    private function renderSqlite(ConnectionInterface $connection): string
    {
        return collect($connection->select(
            "select sql from sqlite_master where type in ('table', 'index') and sql is not null order by name"
        ))->pluck('sql')->implode(";\n\n") . ';';
    }

    /**
     * Ask MySQL for its own CREATE TABLE definition of each table.
     */
    private function renderMysql(ConnectionInterface $connection): string
    {
        return collect($connection->getSchemaBuilder()->getTables())
            ->pluck('name')
            ->map(function (string $name) use ($connection): string {
                $safe = str_replace('`', '``', $name);
                $row = (array) $connection->selectOne("SHOW CREATE TABLE `{$safe}`");

                return $row['Create Table'] ?? '';
            })
            ->filter()
            ->implode(";\n\n") . ';';
    }

    /**
     * Postgres has no single DDL command, so emit the raw column and foreign key metadata as JSON.
     */
    private function renderPgsql(ConnectionInterface $connection): string
    {
        $builder = $connection->getSchemaBuilder();

        return (string) json_encode(
            collect($builder->getTables())
                ->map(fn (array $table): array => [
                    'name' => $table['name'],
                    'columns' => $builder->getColumns($table['name']),
                    'foreign_keys' => $builder->getForeignKeys($table['name']),
                ])
                ->values()
                ->all(),
            JSON_UNESCAPED_SLASHES,
        );
    }

    private function connection(): ConnectionInterface
    {
        return DB::connection(ReadDatabase::CONNECTION_NAME);
    }
}
