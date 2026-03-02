<?php

namespace LaraClaw\Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\Schema;
use LaraClaw\LaraclawServiceProvider;
use LaraClaw\Tests\Fixtures\FakeConversationStore;
use Laravel\Ai\Contracts\ConversationStore;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [LaraclawServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        // Use Laravel's base User model for tests
        $app['config']->set('laraclaw.auth.user_model', User::class);

        // Disable boot-time config validation (only fires outside console anyway)
        $app['config']->set('laraclaw.channels.telegram.enabled', false);
        $app['config']->set('laraclaw.channels.slack.enabled', false);
        $app['config']->set('laraclaw.channels.email.enabled', false);
    }

    protected function defineDatabaseMigrations(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->default('Test User');
            $table->string('email')->unique();
            $table->timestamps();
        });

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Override ConversationStore so no real AI conversation DB is needed
        $this->app->instance(ConversationStore::class, new FakeConversationStore);
    }

    protected function createUser(array $attributes = []): User
    {
        $id = \DB::table('users')->insertGetId(array_merge([
            'name' => 'Test User',
            'email' => 'test-' . uniqid() . '@example.com',
            'created_at' => now(),
            'updated_at' => now(),
        ], $attributes));

        return (new User)->forceFill(['id' => $id]);
    }
}
