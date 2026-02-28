<?php

namespace LaraClaw\Agents;

use LaraClaw\Calendar\Contracts\CalendarDriver;
use LaraClaw\Channels\Channel;
use LaraClaw\Models\Conversation;
use LaraClaw\SkillRegistry;
use Illuminate\Support\Collection;
use LaraClaw\Tools\CalendarManager;
use LaraClaw\Tools\EmailManager;
use LaraClaw\Tools\Files;
use LaraClaw\Tools\HeartbeatManager;
use LaraClaw\Tools\ImageManager;
use LaraClaw\Tools\Persona;
use LaraClaw\Tools\ReminderManager;
use LaraClaw\Tools\TextToSpeech;
use LaraClaw\Tools\UseSkill;
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

    public function __construct(
        private Channel $channel,
        private SkillRegistry $skillRegistry,
        private Collection $replyAttachments,
        private ?Conversation $conversation = null,
        private ?CalendarDriver $calendarDriver = null,
    ) {}

    public function instructions(): string
    {
        $base = $this->buildPrompt();
        $persona = $this->resolvePersona();

        return $base . $persona;
    }

    public function tools(): iterable
    {
        $tools = [
            new UseSkill($this->skillRegistry),
            new ImageManager($this->channel, $this->replyAttachments),
            new Files($this->channel, $this->replyAttachments),
            new WebRequest($this->channel),
            new Persona($this->conversation),
            new ReminderManager($this->channel),
            new HeartbeatManager($this->channel),
        ];

        if (Ai::textProvider(config('ai.default')) instanceof SupportsWebSearch) {
            $tools[] = new WebSearch;
        }

        if (config('laraclaw.channels.email.enabled')) {
            $tools[] = new EmailManager($this->channel, config('laraclaw.channels.email.imap.mailbox', 'default'));
        }

        if (config('laraclaw.tools.tts.enabled')) {
            $tools[] = new TextToSpeech($this->replyAttachments);
        }

        if ($this->calendarDriver) {
            $tools[] = new CalendarManager($this->channel, $this->calendarDriver);
        }

        return $tools;
    }

    private function resolvePersona(): string
    {
        $persona = $this->conversation?->persona ?? config('laraclaw.personas.default');

        if (! $persona) {
            return '';
        }

        $path = config('laraclaw.personas.path') . '/' . basename($persona) . '.md';

        return file_exists($path) ? file_get_contents($path) : '';
    }

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
