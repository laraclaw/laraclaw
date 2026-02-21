<?php

namespace LaraClaw\Agents;

use LaraClaw\PendingAudioReply;
use LaraClaw\PendingImageReply;
use LaraClaw\SkillRegistry;
use LaraClaw\Tools\CalendarManager;
use LaraClaw\Tools\EmailManager;
use LaraClaw\Tools\Files;
use LaraClaw\Tools\ImageManager;
use LaraClaw\Tools\Persona;
use LaraClaw\Tools\TextToSpeech;
use LaraClaw\Tools\UseSkill;
use LaraClaw\Tools\WebRequest;
use LaraClaw\Calendar\Contracts\CalendarDriver;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Providers\SupportsWebSearch;
use Laravel\Ai\Providers\Tools\WebSearch;
use LaraClaw\Channels\Channel;
use LaraClaw\Tables;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Concerns\RemembersConversations;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Messages\AssistantMessage;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Promptable;
use Laravel\Ai\Responses\Data\ToolCall;
use Laravel\Ai\Responses\Data\ToolResult;
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
        $base = 'You are a helpful assistant. '
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

        return $tools;
    }

    /**
     * Override RemembersConversations to properly hydrate tool calls/results.
     */
    public function messages(): iterable
    {
        if (! $this->conversationId) {
            return [];
        }

        return DB::table(Tables::MESSAGES)
            ->where('conversation_id', $this->conversationId)
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->reverse()
            ->values()
            ->flatMap(function ($m) {
                $toolCalls = collect(json_decode($m->tool_calls, true));
                $toolResults = collect(json_decode($m->tool_results, true));

                if ($m->role === 'user') {
                    return [new Message('user', $m->content)];
                }

                if ($toolCalls->isNotEmpty()) {
                    $messages = [];

                    $messages[] = new AssistantMessage(
                        $m->content ?: '',
                        $toolCalls->map(fn ($tc) => new ToolCall(
                            id: $tc['id'],
                            name: $tc['name'],
                            arguments: $tc['arguments'],
                            resultId: $tc['result_id'] ?? null,
                        ))
                    );

                    if ($toolResults->isNotEmpty()) {
                        $messages[] = new ToolResultMessage(
                            $toolResults->map(fn ($tr) => new ToolResult(
                                id: $tr['id'],
                                name: $tr['name'],
                                arguments: $tr['arguments'],
                                result: $tr['result'],
                                resultId: $tr['result_id'] ?? null,
                            ))
                        );
                    }

                    return $messages;
                }

                return [new AssistantMessage($m->content)];
            })
            ->all();
    }
}
