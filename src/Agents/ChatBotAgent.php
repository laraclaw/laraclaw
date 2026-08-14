<?php

namespace Laraclaw\Agents;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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

use function Laraclaw\Support\appTimezone;

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
        $sender = $this->resolveSender();
        $memory = $this->resolveMemoryGuidance();
        $schema = $this->resolveDatabaseSchema();

        return $base . $persona . $sender . $memory . $schema;
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

        return array_merge($tools, $this->configuredTools(), $this->toolRegistry->resolve(
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
     * Build the tools listed in config, handing each one the message and thread it is answering.
     *
     * @return Tool[]
     */
    private function configuredTools(): array
    {
        return collect(config('laraclaw.tools.custom', []))
            ->filter(fn (string $class): bool => $this->isUsableTool($class))
            ->map(fn (string $class): Tool => resolve($class, [
                'message' => $this->message,
                'thread' => $this->thread,
            ]))
            ->values()
            ->all();
    }

    /**
     * Check that a configured tool can actually be built, warning instead of taking the whole reply down.
     *
     * A typo in the class name should cost the user one tool, not every message,
     * so this reports the problem and carries on without it.
     */
    private function isUsableTool(string $class): bool
    {
        if (! class_exists($class)) {
            Log::warning('Configured tool does not exist', ['tool' => $class]);

            return false;
        }

        if (! is_subclass_of($class, Tool::class)) {
            Log::warning('Configured tool does not implement the Tool contract', ['tool' => $class]);

            return false;
        }

        return true;
    }

    /**
     * Name the person being replied to so personas can address them and skills can
     * tell what "I" refers to.
     *
     * Prefers the name the connector read off the message, because a group thread
     * resolves to the configured owner rather than to whoever actually typed.
     */
    private function resolveSender(): string
    {
        $name = $this->sanitizeSenderName($this->message->senderName);

        if (blank($name) && $this->thread->is_direct_message) {
            $name = $this->sanitizeSenderName($this->thread->user()?->name);
        }

        if (blank($name)) {
            return '';
        }

        return $this->thread->is_direct_message
            ? PHP_EOL . PHP_EOL . "You are talking to {$name}."
            : PHP_EOL . PHP_EOL . "This message was sent by {$name}. Other people may be in this chat, so do not assume every message comes from the same person.";
    }

    /**
     * Flatten a sender name into a single short line before it reaches the system prompt.
     *
     * The name comes from a profile the sender controls, so it is treated as text to
     * quote rather than as anything the model should read as instructions.
     */
    private function sanitizeSenderName(?string $name): string
    {
        return Str::of($name ?? '')
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->limit(50, '')
            ->value();
    }

    /**
     * Load the active persona from disk and return its contents.
     * Returns an empty string if no persona is set or the file does not exist.
     */
    private function resolvePersona(): string
    {
        $persona = $this->thread?->persona ?? config('laraclaw.personas.default');

        // With nothing configured, fall back to default.md the way the base system
        // prompt already does, so dropping a file in place is enough to use it. An
        // unset env var arrives as null, but one written with no value arrives as
        // an empty string, and both mean the same thing here. The allowlist check
        // below covers the case where no such file exists.
        if (blank($persona)) {
            $persona = 'default';
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
     * Tell the agent that memory exists and has to be searched on purpose.
     *
     * Without this the memory tool reads like an optional extra, so the agent
     * answers "I do not have that in this chat" from context alone and only goes
     * looking once the user pushes back. The recall was always there; nothing was
     * prompting it to reach for it.
     */
    private function resolveMemoryGuidance(): string
    {
        if (! config('laraclaw.memory.enabled')) {
            return '';
        }

        return PHP_EOL . PHP_EOL . 'Long term memory:' . PHP_EOL . PHP_EOL
            . 'Past conversations are searchable, but none of them are loaded into this chat for you. '
            . 'Anything said before this conversation began exists only if you go looking for it. '
            . 'Search your memory before you answer that you do not know something, that you do not '
            . 'remember it, or that it is not in this chat, and search it whenever the user refers to '
            . 'something you talked about earlier. Treat what comes back as something you were genuinely '
            . 'told, and say when you are drawing on an older conversation.';
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
    private function buildSystemPrompt(): string
    {
        $timezone = appTimezone();
        $now = now()->setTimezone($timezone);

        return file_get_contents($this->instructionsPath())
            . PHP_EOL . PHP_EOL
            . "Current date and time: {$now->toDateTimeString()} ({$timezone}, UTC{$now->format('P')})." . PHP_EOL
            . 'Every time the user mentions is in this timezone unless they say otherwise, '
            . 'and every time you schedule or report back should be in it too.';
    }

    /**
     * Find the base instructions, preferring the published copy over the packaged one.
     */
    private function instructionsPath(): string
    {
        $published = config('laraclaw.agent.instructions', base_path('laraclaw/instructions.md'));

        if (filled($published) && file_exists($published)) {
            return $published;
        }

        return __DIR__ . '/../../resources/instructions.md';
    }
}
