<?php

namespace Laraclaw\Tests\E2E;

use Dotenv\Dotenv;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laraclaw\Enums\ConnectorType;
use Laraclaw\Http\Controllers\ApiController;
use Laraclaw\Http\Middleware\VerifyApiToken;
use Laraclaw\LaraclawServiceProvider;
use Laraclaw\Models\Account;
use Laravel\Ai\AiServiceProvider;
use Laravel\Tinker\TinkerServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Base class for end-to-end tests that hit real provider APIs, a real SQLite file,
 * and the package's HTTP endpoint.
 *
 * Loads tests/E2E/.env.e2e if it exists. When the file is missing or required
 * keys are blank, individual tests skip themselves with markTestSkipped().
 */
#[Group('e2e')]
abstract class E2ETestCase extends BaseTestCase
{
    private static bool $envLoaded = false;

    protected const API_TOKEN = 'laraclaw-e2e-pest-token';

    protected function setUp(): void
    {
        $this->loadEnvFile();

        parent::setUp();

        Route::post('api/message', ApiController::class)->middleware([VerifyApiToken::class]);
    }

    protected function getPackageProviders($app): array
    {
        return [AiServiceProvider::class, TinkerServiceProvider::class, LaraclawServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'e2e');
        $app['config']->set('database.connections.e2e', [
            'driver' => 'sqlite',
            'database' => __DIR__ . '/database.sqlite',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);

        $this->ensureSqliteFile();

        $app['config']->set('laraclaw.auth.user_model', User::class);

        // The non-destructive defaults: connectors we don't exercise stay off so
        // boot-time validation passes without their respective env vars.
        $app['config']->set('laraclaw.connectors.telegram.enabled', false);
        $app['config']->set('laraclaw.connectors.slack.enabled', false);
        $app['config']->set('laraclaw.connectors.email.enabled', false);

        $app['config']->set('cache.default', 'array');
        $app['config']->set('ai.caching.embeddings.store', 'array');

        // AI provider keys come from .env.e2e via $_ENV.
        $app['config']->set('ai.default', $this->envValue('AI_DEFAULT', 'anthropic'));
        $app['config']->set('ai.default_for_embeddings', $this->envValue('AI_DEFAULT_FOR_EMBEDDINGS', 'openai'));
        $app['config']->set('ai.default_for_audio', $this->envValue('AI_DEFAULT_FOR_AUDIO', 'openai'));
        $app['config']->set('ai.providers.openai.key', $this->envValue('OPENAI_API_KEY'));
        $app['config']->set('ai.providers.openai.models.audio.default', $this->envValue('OPENAI_AUDIO_MODEL', 'gpt-4o-mini-tts'));
        $app['config']->set('ai.providers.anthropic.key', $this->envValue('ANTHROPIC_API_KEY'));

        // A working filesystem disk that the FileManager test can write to.
        $app['config']->set('filesystems.disks.laraclaw_files', [
            'driver' => 'local',
            'root' => __DIR__ . '/storage/laraclaw_files',
        ]);
        $app['config']->set('filesystems.disks.local', [
            'driver' => 'local',
            'root' => __DIR__ . '/storage/local',
        ]);
        // Disk config is owned by the harness — tests assert against these specific paths.
        $app['config']->set('laraclaw.filesystem.allowed_disks', ['laraclaw_files']);
        $app['config']->set('laraclaw.filesystem.attachments_disk', 'local');

        $app['config']->set('laraclaw.memory.enabled', $this->envValue('LARACLAW_MEMORY_ENABLED', true));
        $app['config']->set('laraclaw.memory.min_similarity', 0.3);

        $app['config']->set('laraclaw.tools.tts.enabled', true);
        $app['config']->set('laraclaw.tools.tts.voice', 'alloy');
        $app['config']->set('laraclaw.tools.tinker.enabled', true);
        $app['config']->set('laraclaw.tools.read_database.enabled', true);

        if ($this->isDestructiveEnabled()) {
            $app['config']->set('laraclaw.connectors.email.enabled', true);
            $app['config']->set('laraclaw.connectors.email.smtp.host', $this->envValue('LARACLAW_SMTP_HOST'));
            $app['config']->set('laraclaw.connectors.email.smtp.port', (int) $this->envValue('LARACLAW_SMTP_PORT', 587));
            $app['config']->set('laraclaw.connectors.email.smtp.encryption', $this->envValue('LARACLAW_SMTP_ENCRYPTION', 'tls'));
            $app['config']->set('laraclaw.connectors.email.smtp.username', $this->envValue('LARACLAW_SMTP_USERNAME'));
            $app['config']->set('laraclaw.connectors.email.smtp.password', $this->envValue('LARACLAW_SMTP_PASSWORD'));
            $app['config']->set('laraclaw.connectors.email.smtp.from_address', $this->envValue('LARACLAW_SMTP_FROM_ADDRESS'));
            $app['config']->set('laraclaw.connectors.email.smtp.from_name', $this->envValue('LARACLAW_SMTP_FROM_NAME', 'Laraclaw E2E'));

            $app['config']->set('laraclaw.tools.calendar_manager.driver', $this->envValue('LARACLAW_CALENDAR_DRIVER'));
            $app['config']->set('laraclaw.tools.calendar_manager.google.calendar_id', $this->envValue('LARACLAW_GOOGLE_CALENDAR_ID'));
            $app['config']->set('laraclaw.tools.calendar_manager.google.credentials_json', $this->envValue('LARACLAW_GOOGLE_CREDENTIALS_JSON'));
            $app['config']->set('laraclaw.tools.calendar_manager.google.token_json', $this->envValue('LARACLAW_GOOGLE_TOKEN_JSON'));
        }
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->default('Test User');
            $table->string('email')->unique();
            $table->timestamps();
        });

        $this->loadMigrationsFrom(__DIR__ . '/../../database/migrations');
        $this->loadMigrationsFrom(__DIR__ . '/../../vendor/laravel/ai/database/migrations');
    }

    /**
     * Create a user and register the API token so /api/message authenticates.
     *
     * The returned User is a stand-in for whatever model the consumer wires up via
     * laraclaw.auth.user_model; tests only need it for its identifier when the
     * VerifyApiToken middleware sets $request->user(). Since database.default is
     * the e2e connection in defineEnvironment(), any subsequent ->save() or
     * relationship lookup against this instance will use the right connection.
     */
    protected function authenticatedUser(): User
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'E2E user',
            'email' => 'e2e-' . uniqid() . '@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = (new User)->forceFill(['id' => $id]);

        Account::updateOrCreate(
            ['user_id' => $id, 'connector' => ConnectorType::Api],
            ['account' => hash('sha256', self::API_TOKEN)],
        );

        config(['laraclaw.auth.admin_user_id' => $id]);

        return $user;
    }

    /**
     * Auth header for /api/message.
     */
    protected function apiHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . self::API_TOKEN];
    }

    /**
     * Send a message, chained on $key if provided, and return the decoded payload.
     */
    protected function postMessage(string $text, ?string $key = null): array
    {
        $body = ['text' => $text];

        if ($key !== null) {
            $body['key'] = $key;
        }

        $response = $this->postJson('/api/message', $body, $this->apiHeaders());

        $response->assertOk();

        return $response->json();
    }

    /**
     * Skip the test when an env var that the test needs is blank.
     */
    protected function requireEnv(string ...$keys): void
    {
        foreach ($keys as $key) {
            if (blank($this->envValue($key))) {
                $this->markTestSkipped("E2E env var {$key} is not set in tests/E2E/.env.e2e");
            }
        }
    }

    /**
     * Skip the test unless LARACLAW_E2E_DESTRUCTIVE=1 (real email, real calendar event).
     */
    protected function requireDestructive(): void
    {
        if (! $this->isDestructiveEnabled()) {
            $this->markTestSkipped('Destructive E2E tests are off. Set LARACLAW_E2E_DESTRUCTIVE=1 in .env.e2e to enable.');
        }
    }

    /**
     * Return true when destructive tests are opted in. Accepts truthy strings
     * like "1", "true", "yes" so contributors do not have to remember the exact value.
     */
    protected function isDestructiveEnabled(): bool
    {
        $raw = $_ENV['LARACLAW_E2E_DESTRUCTIVE'] ?? $_SERVER['LARACLAW_E2E_DESTRUCTIVE'] ?? getenv('LARACLAW_E2E_DESTRUCTIVE');

        return (bool) filter_var($raw, FILTER_VALIDATE_BOOL);
    }

    /**
     * Read a key from .env.e2e (loaded into $_ENV via Dotenv). Bypasses config()
     * because these values are test-only knobs, not part of the package's config schema.
     *
     * Returns the same shape Laravel's env() helper does:
     *   "true" / "(true)"   -> bool true
     *   "false" / "(false)" -> bool false
     *   "null" / "(null)"   -> null
     *   "empty" / "(empty)" -> ""
     *   ""                  -> default (treat blanks as missing)
     *   anything else       -> the raw string
     * For boolean-shaped values that the package's config does not coerce on its
     * own (e.g. "1", "yes", "on"), use FILTER_VALIDATE_BOOL at the call site or
     * route through a typed helper like isDestructiveEnabled().
     */
    protected function envValue(string $key, mixed $default = null): mixed
    {
        $raw = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($raw === false || $raw === null) {
            return $default;
        }

        return match (strtolower((string) $raw)) {
            '' => $default,
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $raw,
        };
    }

    /**
     * Load tests/E2E/.env.e2e into $_ENV / putenv() so env() reads it. Runs once.
     */
    private function loadEnvFile(): void
    {
        if (self::$envLoaded) {
            return;
        }

        $path = __DIR__ . '/.env.e2e';

        if (file_exists($path)) {
            // Mutable so .env.e2e always wins for the e2e suite, even when the
            // shell already exports OPENAI_API_KEY or another LARACLAW_* var.
            Dotenv::createMutable(__DIR__, '.env.e2e')->safeLoad();
        }

        self::$envLoaded = true;
    }

    /**
     * Wipe and recreate the SQLite file each setUp so every test starts on an empty
     * schema with no leftover rows from earlier tests in the same Pest invocation.
     * The cost is dominated by the real AI calls each test makes (5 to 15 seconds),
     * so the extra migration time per test is in the noise.
     */
    private function ensureSqliteFile(): void
    {
        $path = __DIR__ . '/database.sqlite';

        if (file_exists($path)) {
            unlink($path);
        }

        touch($path);
    }
}
