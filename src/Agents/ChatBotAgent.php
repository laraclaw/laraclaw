<?php

namespace LaraClaw\Agents;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use LaraClaw\Calendar\Contracts\CalendarDriver;
use LaraClaw\Commands\CommandRegistry;
use LaraClaw\Message;
use LaraClaw\Models\Conversation;
use LaraClaw\Models\UserAccount;
use LaraClaw\SkillRegistry;
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
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Providers\SupportsWebSearch;
use Laravel\Ai\Promptable;
use Laravel\Ai\Providers\Tools\WebSearch;
use Laravel\Ai\Responses\StreamableAgentResponse;

class ChatBotAgent implements Agent, Conversational, HasTools
{
    use Promptable, RemembersConversations;

    public ?string $prompt = null;

    /** @var array<int, \Laravel\Ai\Files\Image|\Laravel\Ai\Files\Document> */
    public array $attachments = [];

    /** @var Collection<int, \LaraClaw\DTOs\Attachment> */
    public Collection $replyAttachments;

    private ?Conversation $conversation = null;

    /**
     * Resolve the sender, conversation, and agent context from the incoming message.
     */
    public function __construct(
        private Message $message,
        ConversationStore $conversations,
        CommandRegistry $commandRegistry,
        private SkillRegistry $skillRegistry,
        private ToolRegistry $toolRegistry,
        private ?CalendarDriver $calendarDriver = null,
    ) {
        $this->replyAttachments = collect();

        $context = $this->resolveContext($conversations);

        if ($context === null) {
            return;
        }

        ['user' => $user, 'conversation' => $this->conversation, 'conversationId' => $conversationId] = $context;

        // Pass raw text to the command registry first so we don't transcribe audio
        // unless we know the message will actually reach the agent.
        $text = $this->processCommand($message->text ?? '', $commandRegistry);

        if ($text === null) {
            return;
        }

        // If no command rewrote the text, resolve the full agent text now (may transcribe audio).
        $this->prompt = ($text !== ($message->text ?? '')) ? $text : $message->agentText();
        $this->attachments = $message->agentAttachments();

        if ($conversationId) {
            $this->continue($conversationId, as: $user);
        } else {
            $this->forUser($user);
        }
    }

    /**
     * Return true if the agent has a prompt ready to send to the AI.
     * False means a command consumed the message or no owner user is configured.
     */
    public function isReady(): bool
    {
        return $this->prompt !== null;
    }

    /**
     * Send the prepared prompt and return the full response text.
     */
    public function send(): string
    {
        return (string) $this->prompt($this->prompt, $this->attachments);
    }

    /**
     * Stream the prepared prompt, yielding events as they arrive.
     */
    public function run(): StreamableAgentResponse
    {
        return $this->stream($this->prompt, $this->attachments);
    }

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
     * Look up the sender and find or create their conversation record.
     *
     * Returns null if this is a group message and no owner user is configured,
     * in which case processing should stop.
     *
     * @return array{user: mixed, conversation: Conversation, conversationId: string|null}|null
     */
    private function resolveContext(ConversationStore $conversations): ?array
    {
        $channel = $this->message->channel;

        if ($this->message->conversationIsDirectMessage) {
            $userAccount = UserAccount::where('channel', $channel->name)
                ->where('account', $this->message->conversationKey)
                ->with('user')
                ->firstOrFail();

            $user = $userAccount->user;

            $conversation = Conversation::firstOrCreate([
                'channel' => $channel->name,
                'key' => $this->message->conversationKey,
            ]);

            $startFresh = Cache::pull("new_conversation:{$channel->name}:{$this->message->conversationKey}");
            $conversationId = $startFresh ? null : $conversations->latestConversationId($user->getAuthIdentifier());

            return compact('user', 'conversation', 'conversationId');
        }

        // Group channels always run as the owner user with one conversation per channel.
        $userModel = config('laraclaw.auth.user_model');
        $user = $userModel::find(config('laraclaw.auth.admin_user_id'));

        if (! $user) {
            Log::warning('LaraClaw: no owner user configured (LARACLAW_OWNER_ID)');

            return null;
        }

        // The !new command is scoped to individual users so it cannot reset a shared group conversation.
        $conversation = Conversation::where('channel', $channel->name)
            ->where('key', $this->message->conversationKey)
            ->first();

        if (! $conversation) {
            try {
                $conversation = Conversation::create([
                    'channel' => $channel->name,
                    'key' => $this->message->conversationKey,
                    'conversation_id' => $conversations->storeConversation(
                        $user->getAuthIdentifier(),
                        $channel->name . ':' . $this->message->conversationKey,
                    ),
                ]);
            } catch (UniqueConstraintViolationException) {
                $conversation = Conversation::where('channel', $channel->name)
                    ->where('key', $this->message->conversationKey)
                    ->firstOrFail();
            }
        }

        $conversationId = $conversation->conversation_id;

        return compact('user', 'conversation', 'conversationId');
    }

    /**
     * Check if the message matches a registered command and run it.
     *
     * Returns null if the command took full ownership of the message and nothing more should happen.
     * Otherwise returns the text to continue with, which the command may have rewritten.
     */
    private function processCommand(string $text, CommandRegistry $commandRegistry): ?string
    {
        $command = $commandRegistry->match($text);

        if (! $command) {
            return $text;
        }

        $result = $command->handle($this->message);

        if ($result === null) {
            return null;
        }

        return $result->text ?? $text;
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

        $personasPath = config('laraclaw.personas.path');
        $allowed = collect(glob($personasPath . '/*.md') ?: [])
            ->map(fn ($f) => pathinfo($f, PATHINFO_FILENAME))
            ->all();

        $stem = basename($persona);

        if (! in_array($stem, $allowed, true)) {
            return '';
        }

        return file_get_contents($personasPath . '/' . $stem . '.md');
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
