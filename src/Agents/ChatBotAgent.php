<?php

namespace LaraClaw\Agents;

use Illuminate\Support\Collection;
use LaraClaw\Calendar\Contracts\CalendarDriver;
use LaraClaw\DTOs\Attachment;
use LaraClaw\Message;
use LaraClaw\Middleware\TranscribeAudio;
use LaraClaw\Models\Conversation;
use LaraClaw\SkillRegistry;
use LaraClaw\Tools\Bash;
use LaraClaw\Tools\CalendarManager;
use LaraClaw\Tools\EmailManager;
use LaraClaw\Tools\Files;
use LaraClaw\Tools\HeartbeatManager;
use LaraClaw\Tools\ImageManager;
use LaraClaw\Tools\Persona;
use LaraClaw\Tools\ReminderManager;
use LaraClaw\Tools\TextToSpeech;
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
use Laravel\Ai\Files\Document;
use Laravel\Ai\Files\Image;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\WebSearch;

class ChatBotAgent implements Agent, Conversational, HasMiddleware, HasTools
{
    use Promptable, RemembersConversations;

    /** @var Collection<int, Attachment> */
    public Collection $replyAttachments;

    public string $inputText = '';

    /** @var array<int, Image|Document> */
    public array $inputAttachments = [];

    public function __construct(
        private Message $message,
        private SkillRegistry $skillRegistry,
        private ToolRegistry $toolRegistry,
        private ?Conversation $conversation = null,
        private ?CalendarDriver $calendarDriver = null,
    ) {
        $this->replyAttachments = collect();
    }

    /**
     * Prompt the agent using the input text and attachments set by the Gateway.
     */
    public function run(): string
    {
        return (string) $this->prompt($this->inputText, $this->inputAttachments);
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
            new ImageManager($this->message, $this->replyAttachments),
            new Files($this->message, $this->replyAttachments),
            new WebRequest($this->message),
            new Persona($this->conversation),
            new ReminderManager($this->message),
            new HeartbeatManager($this->message),
        ];

        if (Ai::textProvider(config('ai.default')) instanceof SupportsWebSearch) {
            $tools[] = new WebSearch;
        }

        if (config('laraclaw.channels.email.enabled')) {
            $tools[] = new EmailManager($this->message, config('laraclaw.channels.email.imap.mailbox', 'default'));
        }

        if (config('laraclaw.tools.tts.enabled')) {
            $tools[] = new TextToSpeech($this->replyAttachments);
        }

        if (config('laraclaw.tools.bash.enabled')) {
            $tools[] = new Bash;
        }

        if ($this->calendarDriver) {
            $tools[] = new CalendarManager($this->message, $this->calendarDriver);
        }

        return array_merge($tools, $this->toolRegistry->resolve(
            $this->message,
            $this->replyAttachments,
            $this->conversation,
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
     * Load the active persona from disk and return its contents.
     * Returns an empty string if no persona is set or the file does not exist.
     */
    private function resolvePersona(): string
    {
        $persona = $this->conversation?->persona ?? config('laraclaw.personas.default');

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
