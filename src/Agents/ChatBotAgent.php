<?php

namespace Laraclaw\Agents;

use Laraclaw\Agents\Middleware\TranscribeAudio;
use Laraclaw\DTOs\IncomingMessage;
use Laraclaw\Models\Thread;
use Laraclaw\Services\Calendar\Contracts\CalendarDriver;
use Laraclaw\Services\DatabaseSchemaReader;
use Laraclaw\Skills\SkillRegistry;
use Laraclaw\Tools\CalendarManager;
use Laraclaw\Tools\EmailManager;
use Laraclaw\Tools\FileManager;
use Laraclaw\Tools\HeartbeatManager;
use Laraclaw\Tools\ImageManager;
use Laraclaw\Tools\MemoryManager;
use Laraclaw\Tools\Persona;
use Laraclaw\Tools\ReadDatabase;
use Laraclaw\Tools\ReminderManager;
use Laraclaw\Tools\TextToSpeech;
use Laraclaw\Tools\Tinker;
use Laraclaw\Tools\ToolRegistry;
use Laraclaw\Tools\UseSkill;
use Laraclaw\Tools\WebRequest;
use Laravel\Ai\Ai;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Providers\SupportsWebSearch;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\WebSearch;
use RuntimeException;

class ChatBotAgent implements Agent, Conversational, HasMiddleware, HasProviderOptions, HasTools
{
    use Promptable, RemembersConversations;

    /**
     * Resolve the owner from the thread and bind the existing conversation, if any.
     */
    public function __construct(
        public readonly IncomingMessage $message,
        private SkillRegistry $skillRegistry,
        private ToolRegistry $toolRegistry,
        public readonly Thread $thread,
        private DatabaseSchemaReader $schemaReader,
        private ?CalendarDriver $calendarDriver = null,
    ) {
        $user = $this->thread->user() ?? throw new RuntimeException('No user found for thread.');

        if ($thread->conversation_id) {
            $this->continue($thread->conversation_id, as: $user);
        } else {
            $this->forUser($user);
        }
    }

    /**
     * Build the system instructions for the agent, appending the active persona.
     */
    public function instructions(): string
    {
        $base = $this->buildSystemPrompt();
        $persona = $this->resolvePersona();
        $schema = $this->resolveDatabaseSchema();

        return $base . $persona . $schema;
    }

    /**
     * Return the tool instances available to the agent.
     */
    public function tools(): iterable
    {
        $tools = [
            resolve(UseSkill::class, ['registry' => $this->skillRegistry]),
            resolve(Persona::class, ['thread' => $this->thread]),
            resolve(ImageManager::class, ['message' => $this->message]),
            resolve(FileManager::class, ['message' => $this->message]),
            resolve(ReminderManager::class, ['message' => $this->message]),
            resolve(HeartbeatManager::class, ['message' => $this->message]),
            resolve(WebRequest::class, ['message' => $this->message]),
        ];

        if (Ai::textProvider(config('ai.default')) instanceof SupportsWebSearch) {
            $tools[] = resolve(WebSearch::class);
        }

        if (config('laraclaw.connectors.email.enabled')) {
            $tools[] = resolve(EmailManager::class, ['message' => $this->message, 'mailbox' => config('laraclaw.connectors.email.imap.mailbox', 'default')]);
        }

        if ($this->calendarDriver) {
            $tools[] = resolve(CalendarManager::class, ['message' => $this->message, 'driver' => $this->calendarDriver]);
        }

        if (config('laraclaw.memory.enabled')) {
            $tools[] = resolve(MemoryManager::class);
        }

        if (config('laraclaw.tools.tts.enabled')) {
            $tools[] = resolve(TextToSpeech::class, ['message' => $this->message]);
        }

        if (config('laraclaw.tools.tinker.enabled')) {
            $tools[] = resolve(Tinker::class);
        }

        if (config('laraclaw.tools.read_database.enabled')) {
            $tools[] = resolve(ReadDatabase::class);
        }

        return array_merge($tools, $this->toolRegistry->resolve(
            $this->message,
            $this->thread,
        ));
    }

    /**
     * Return the middleware that runs before each prompt.
     */
    public function middleware(): array
    {
        return [
            new TranscribeAudio($this->message),
        ];
    }

    /**
     * Opt into prompt caching for providers that support it.
     *
     * Anthropic does not cache by default; the top-level cache_control hint
     * tells it to place an automatic cache breakpoint on the longest cacheable
     * prefix (system prompt and tool definitions).
     *
     * OpenAI caches automatically; prompt_cache_key just routes our requests to
     * the same cache shard so the hit rate stays high across users.
     */
    public function providerOptions(Lab|string $provider): array
    {
        return match ($provider) {
            Lab::Anthropic => ['cache_control' => ['type' => 'ephemeral']],
            Lab::OpenAI => ['prompt_cache_key' => 'laraclaw-chatbot'],
            default => [],
        };
    }

    /**
     * Load the active persona from disk and return its contents.
     * Returns an empty string if no persona is set or the file does not exist.
     */
    private function resolvePersona(): string
    {
        $persona = $this->thread?->persona ?? config('laraclaw.personas.default');

        if (! $persona) {
            return '';
        }

        $personasPath = config('laraclaw.personas.path');
        $allowed = collect(glob($personasPath . '/*.md') ?: [])
            ->map(fn ($f): string => pathinfo($f, PATHINFO_FILENAME))
            ->all();

        $stem = basename($persona);

        if (! in_array($stem, $allowed, true)) {
            return '';
        }

        return file_get_contents($personasPath . '/' . $stem . '.md');
    }

    /**
     * Append the readonly DB schema to the system prompt when the Read Database tool is enabled.
     */
    private function resolveDatabaseSchema(): string
    {
        if (! config('laraclaw.tools.read_database.enabled')) {
            return '';
        }

        $snapshot = $this->schemaReader->render();

        if ($snapshot === '') {
            return '';
        }

        return PHP_EOL . PHP_EOL . 'Database schema (tables, columns, and foreign keys):' . PHP_EOL . PHP_EOL . $snapshot;
    }

    /**
     * Load the base system prompt, preferring the published copy over the package default.
     * Appends the current date and timezone so the agent is always grounded in time.
     */
    private function buildSystemPrompt(string $name = 'default'): string
    {
        $published = resource_path("laraclaw/prompts/{$name}.md");
        $tz = config('app.timezone', 'UTC');
        $now = now()->setTimezone($tz)->toDateTimeString();

        return file_get_contents(
            file_exists($published) ? $published : __DIR__ . "/../../resources/prompts/{$name}.md"
        ) . PHP_EOL . PHP_EOL . "Current date and time: {$now} ({$tz})";
    }
}
