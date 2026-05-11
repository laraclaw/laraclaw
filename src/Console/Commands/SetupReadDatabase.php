<?php

namespace Laraclaw\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Laraclaw\Console\Concerns\ConfiguresEnv;
use Laraclaw\Tools\ReadDatabase;
use RuntimeException;
use Throwable;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\password as promptPassword;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

/**
 * Wizard for the Read Database tool. Provisions a readonly database connection for the agent to query.
 */
class SetupReadDatabase extends Command
{
    use ConfiguresEnv;

    protected $signature = 'laraclaw:setup-read-database';

    protected $description = 'Set up the read-only database connection used by the Read Database tool';

    /**
     * Dispatch to the setup branch that matches the app's default database driver.
     */
    public function handle(): int
    {
        $this->heading('🗄️ Read Database');

        $default = config('database.default');
        $primary = config("database.connections.{$default}");
        $driver = $primary['driver'] ?? null;

        return match ($driver) {
            'mysql', 'mariadb', 'pgsql' => $this->setupSqlServer($driver, $primary),
            'sqlite' => $this->setupSqlite(),
            default => $this->unsupported($driver),
        };
    }

    /**
     * Walk the operator through pointing Laraclaw at a user with only SELECT grants on MySQL, MariaDB, or Postgres.
     */
    private function setupSqlServer(string $driver, array $primary): int
    {
        $choice = select(
            label: 'Read-only database user',
            options: [
                'have' => 'I have a read-only database user ready',
                'need' => 'I need to create a read-only database user',
            ],
        );

        if ($choice === 'need') {
            note('Create the user by running SQL like this, substituting your own username and password:');
            $this->line(PHP_EOL . $this->grantSqlTemplate($driver, $primary['database']) . PHP_EOL);

            if (! confirm(label: 'Ready?', default: true)) {
                info('Cancelled. Re-run laraclaw:setup-read-database once the user exists.');

                return self::FAILURE;
            }
        }

        $username = text(
            label: 'Username for the read-only user',
            default: $this->readEnv('LARACLAW_READ_DATABASE_USERNAME') ?? '',
            required: true,
        );

        $password = promptPassword(label: 'Password for the read-only user', required: true);

        if (! $this->verifyConnection($primary, $username, $password)) {
            warning('Could not connect with those credentials. Double-check the user exists and has SELECT, then re-run laraclaw:setup-read-database.');

            return self::FAILURE;
        }

        $this->saveEnv([
            'LARACLAW_READ_DATABASE_ENABLED' => 'true',
            'LARACLAW_READ_DATABASE_USERNAME' => $username,
            'LARACLAW_READ_DATABASE_PASSWORD' => $password,
        ]);

        info('Read-only DB connection verified and saved.');

        return self::SUCCESS;
    }

    /**
     * Enable the tool for SQLite. The same file is opened in readonly mode by the service provider.
     */
    private function setupSqlite(): int
    {
        info('SQLite will be opened in read-only mode via the same database file. No credentials are needed.');

        $this->saveEnv(['LARACLAW_READ_DATABASE_ENABLED' => 'true']);

        return self::SUCCESS;
    }

    /**
     * Disable the tool when the default connection is on an unsupported driver.
     */
    private function unsupported(?string $driver): int
    {
        warning("Read Database is not supported for the '{$driver}' driver. Skipping.");

        $this->saveEnv(['LARACLAW_READ_DATABASE_ENABLED' => 'false']);

        return self::FAILURE;
    }

    /**
     * Render a CREATE USER + GRANT SELECT template with placeholders for the operator to fill in.
     */
    private function grantSqlTemplate(string $driver, string $database): string
    {
        return match ($driver) {
            'mysql', 'mariadb' => implode(PHP_EOL, [
                "CREATE USER '<username>'@'%' IDENTIFIED BY '<password>';",
                "GRANT SELECT ON `{$database}`.* TO '<username>'@'%';",
                'FLUSH PRIVILEGES;',
            ]),
            'pgsql' => implode(PHP_EOL, [
                "CREATE USER <username> WITH PASSWORD '<password>';",
                "GRANT CONNECT ON DATABASE {$database} TO <username>;",
                'GRANT USAGE ON SCHEMA public TO <username>;',
                'GRANT SELECT ON ALL TABLES IN SCHEMA public TO <username>;',
                'ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT ON TABLES TO <username>;',
            ]),
            default => '',
        };
    }

    /**
     * Probe information_schema with the supplied credentials so users with no SELECT grants
     * actually fail. A bare `select 1` would pass on a user that has USAGE but no table reads.
     */
    private function verifyConnection(array $primary, string $username, string $password): bool
    {
        config([
            'database.connections.' . ReadDatabase::CONNECTION_NAME => ReadDatabase::makeConnectionConfig($primary, $username, $password),
        ]);

        try {
            return spin(function () use ($primary): bool {
                DB::purge(ReadDatabase::CONNECTION_NAME);

                $connection = DB::connection(ReadDatabase::CONNECTION_NAME);
                $probe = match ($primary['driver']) {
                    'mysql', 'mariadb' => 'SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() LIMIT 1',
                    'pgsql' => "SELECT 1 FROM information_schema.tables WHERE table_schema NOT IN ('pg_catalog', 'information_schema') LIMIT 1",
                };

                $rows = $connection->select($probe);

                if ($rows === []) {
                    throw new RuntimeException(
                        'Connected, but no readable tables were found. Either the user has no SELECT grants, or the database has no tables yet.'
                    );
                }

                return true;
            }, 'Verifying read-only credentials');
        } catch (Throwable $e) {
            $this->line("  <fg=red>{$e->getMessage()}</>");

            return false;
        }
    }
}
