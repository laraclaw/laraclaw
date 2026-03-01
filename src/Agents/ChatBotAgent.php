<?php

namespace LaraClaw\Agents;

use Illuminate\Support\Collection;
use LaraClaw\Calendar\Contracts\CalendarDriver;
use LaraClaw\Message;
use LaraClaw\Models\Conversation;
use LaraClaw\SkillRegistry;
use LaraClaw\Tools\CalendarManager;
use LaraClaw\Tools\EmailManager;
use LaraClaw\Tools\Files;
use LaraClaw\Tools\HeartbeatManager;
use LaraClaw\Tools\ImageManager;
use LaraClaw\Tools\Persona;
use LaraClaw\Tools\ReminderManager;
use LaraClaw\Tools\TextToSpeech;
use LaraClaw\Tools\UseSkill;
use LaraClaw\Tools\ToolRegistry;
use LaraClaw\Tools\WebRequest;
use Laravel\Ai\Ai;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Providers\SupportsWebSearch;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\WebSearch;

class ChatBotAgent implements Agent, Conversational, HasTools
{
    use Promptable, RemembersConversations;

    /**
     * Create a new ChatBotAgent instance.
     */
    public function __construct(
        private Message $message,
        private SkillRegistry $skillRegistry,
        private Collection $replyAttachments,
        private ToolRegistry $toolRegistry,
        private ?Conversation $conversation = null,
        private ?CalendarDriver $calendarDriver = null,
    ) {}

    /**
     * Build the system instructions for the agent, appending the active persona.
     */
    public function instructions(): string
    {
        $base = $this->buildPrompt();
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
     * Load the active persona prompt from disk, returning an empty string
     * if no persona is configured or the file does not exist.
     */
    private function resolvePersona(): string
    {
        $persona = $this->conversation?->persona ?? config('laraclaw.personas.default');

        if (! $persona) {
            return '';
        }

        $path = config('laraclaw.personas.path') . '/' . basename($persona) . '.md';

        return file_exists($path) ? file_get_contents($path) : '';
    }

    /**
     * Load the base prompt from the published resource path, falling back
     * to the package default, and append the current date and timezone.
     */
    private function buildPrompt(string $name = 'default'): string
    {
        $published = resource_path("laraclaw/prompts/{$name}.md");
        $tz = config('app.timezone', 'UTC');
        $now = now()->setTimezone($tz)->toDateTimeString();

        return file_get_contents(
            file_exists($published) ? $published : __DIR__ . "/../../resources/prompts/{$name}.md"
        ) . PHP_EOL . PHP_EOL . "Current date and time: {$now} ({$tz})";
    }
}
