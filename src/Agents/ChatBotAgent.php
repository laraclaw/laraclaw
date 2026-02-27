<?php

namespace LaraClaw\Agents;

use LaraClaw\Calendar\Contracts\CalendarDriver;
use LaraClaw\Channels\Channel;
use LaraClaw\PendingAudioReply;
use LaraClaw\PendingImageReply;
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
use LaraClaw\Tools\WebRequest;
use Laravel\Ai\Ai;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Providers\SupportsWebSearch;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\WebSearch;
use Stringable;

class ChatBotAgent implements Agent, Conversational, HasTools
{
    use Promptable, RemembersConversations;

    public function __construct(
        private Channel $channel,
        private ?CalendarDriver $calendarDriver = null,
    ) {}

    public function instructions(): Stringable|string
    {
        $persona = $this->resolvePersona();
        $base = $this->buildPrompt('default');

        return $persona ? $persona . PHP_EOL . PHP_EOL . $base : $base;
    }

    public function tools(): iterable
    {
        $tools = [
            new UseSkill(app(SkillRegistry::class)),
            new Files($this->channel),
            new ImageManager($this->channel, app(PendingImageReply::class)),
            new WebRequest($this->channel),
            new Persona,
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
            $tools[] = new TextToSpeech(app(PendingAudioReply::class));
        }

        if ($this->calendarDriver) {
            $tools[] = new CalendarManager($this->channel, $this->calendarDriver);
        }

        return $tools;
    }

    // Inject the default persona only for new conversations. Existing ones have
    // it in their message history already, so injecting it again would cause
    // duplicate persona instructions in conversation context on each turn.
    private function resolvePersona(): ?string
    {
        if ($this->conversationId) {
            return null;
        }

        $persona = config('laraclaw.personas.default');

        if (! $persona) {
            return null;
        }

        $path = config('laraclaw.personas.path') . '/' . basename($persona) . '.md';

        return file_exists($path) ? file_get_contents($path) : null;
    }

    private function buildPrompt(string $name): string
    {
        $published = resource_path("laraclaw/prompts/{$name}.md");
        $tz = config('app.timezone', 'UTC');
        $now = now()->setTimezone($tz)->toDateTimeString();

        return file_get_contents(
            file_exists($published) ? $published : __DIR__ . "/../../resources/prompts/{$name}.md"
        ) . PHP_EOL . PHP_EOL . "Current date and time: {$now} ({$tz})";
    }
}
