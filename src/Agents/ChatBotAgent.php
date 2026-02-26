<?php

namespace LaraClaw\Agents;

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
use LaraClaw\Calendar\Contracts\CalendarDriver;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Providers\SupportsWebSearch;
use Laravel\Ai\Providers\Tools\WebSearch;
use LaraClaw\Channels\Channel;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Promptable;
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
        $tz = config('app.timezone', 'UTC');
        $now = now()->setTimezone($tz)->toDateTimeString();
        $base = "You are a helpful assistant. The current date and time is {$now} ({$tz}). "
            . 'Be direct and action-oriented. '
            . 'When asked to do something, just do it — use sensible defaults and act immediately rather than asking clarifying questions upfront. '
            . 'Only ask a question if the task is genuinely impossible to attempt without the missing information. '
            . 'For dates and times, use the current date and timezone above as reference unless the user specifies otherwise. '
            . 'For files and locations, use the most obvious choice unless told otherwise. '
            . 'Keep replies concise. '
            . 'IMPORTANT: Before calling any tool, check your conversation history. '
            . 'If you already called the same tool with the same arguments, DO NOT call it again. '
            . 'Instead, reference the previous result in your response.';

        // Only inject default persona for new conversations; existing ones carry it in message history.
        if (! $this->conversationId) {
            $persona = config('laraclaw.persona.default');

            if ($persona) {
                $path = config('laraclaw.persona.path').'/'.basename($persona).'.md';

                if (file_exists($path)) {
                    return file_get_contents($path)."\n\n".$base;
                }
            }
        }

        return $base;
    }

    /**
     * Get the tools available to the agent.
     *
     * @return Tool[]
     */
    public function tools(): iterable
    {
        $tools = [
            new UseSkill(app(SkillRegistry::class)),
            new Files($this->channel),
            new ImageManager($this->channel, app(PendingImageReply::class)),
            new WebRequest($this->channel),
            new Persona,
        ];

        if (Ai::textProvider(config('ai.default')) instanceof SupportsWebSearch) {
            $tools[] = new WebSearch;
        }

        if (config('laraclaw.email.enabled')) {
            $tools[] = new EmailManager($this->channel, config('laraclaw.email.mailbox', 'default'));
        }

        if (config('laraclaw.tts.enabled')) {
            $tools[] = new TextToSpeech(app(PendingAudioReply::class));
        }

        if ($this->calendarDriver) {
            $tools[] = new CalendarManager($this->channel, $this->calendarDriver);
        }

        $tools[] = new ReminderManager($this->channel);
        $tools[] = new HeartbeatManager($this->channel);

        return $tools;
    }

}
