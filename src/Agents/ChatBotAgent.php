<?php

namespace LaraClaw\Agents;

use LaraClaw\Agents\Middleware\TranscribeAudio;
use LaraClaw\Calendar\Contracts\CalendarDriver;
use LaraClaw\DTOs\IncomingMessage;
use LaraClaw\Models\Thread;
use LaraClaw\Skills\SkillRegistry;
use LaraClaw\Tools\BaseTool;
use LaraClaw\Tools\Bash;
use LaraClaw\Tools\CalendarManager;
use LaraClaw\Tools\EmailManager;
use LaraClaw\Tools\FileManager;
use LaraClaw\Tools\HeartbeatManager;
use LaraClaw\Tools\ImageManager;
use LaraClaw\Tools\Persona;
use LaraClaw\Tools\ReminderManager;
use LaraClaw\Tools\TextToSpeech;
use LaraClaw\Tools\Tinker;
use LaraClaw\Tools\ToolRegistry;
use LaraClaw\Tools\UseSkill;
use LaraClaw\Tools\WebRequest;
use Laravel\Ai\Ai;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Providers\SupportsWebSearch;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\WebSearch;
use RuntimeException;

class ChatBotAgent implements Agent, Conversational, HasMiddleware, HasTools
{
    use Promptable, RemembersConversations;

    public function __construct(
        private IncomingMessage $message,
        private SkillRegistry $skillRegistry,
        private ToolRegistry $toolRegistry,
        private Thread $thread,
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

        return $base . $persona;
    }

    /**
     * Return the tool instances available to the agent.
     */
    public function tools(): iterable
    {
        $tools = [
            new UseSkill($this->skillRegistry),
            resolve(ImageManager::class, ['message' => $this->message]),
            resolve(FileManager::class, ['message' => $this->message]),
            new WebRequest($this->message),
            new Persona($this->thread),
            new ReminderManager($this->message),
            new HeartbeatManager($this->message),
        ];

        if (Ai::textProvider(config('ai.default')) instanceof SupportsWebSearch) {
            $tools[] = new WebSearch;
        }

        if (config('laraclaw.connectors.email.enabled')) {
            $tools[] = new EmailManager($this->message, config('laraclaw.connectors.email.imap.mailbox', 'default'));
        }

        if (config('laraclaw.tools.tts.enabled')) {
            $tools[] = resolve(TextToSpeech::class, ['message' => $this->message]);
        }

        if (config('laraclaw.tools.bash.enabled')) {
            $tools[] = new Bash;
        }

        if (config('laraclaw.tools.tinker.enabled')) {
            $tools[] = new Tinker;
        }

        if ($this->calendarDriver) {
            $tools[] = new CalendarManager($this->message, $this->calendarDriver);
        }

        $all = array_merge($tools, $this->toolRegistry->resolve(
            $this->message,
            $this->thread,
        ));

        $connector = $this->thread->connector();

        return array_map(
            fn ($tool): Tool|WebSearch => $tool instanceof BaseTool ? $tool->withConnector($connector) : $tool,
            $all,
        );
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
