<?php

namespace Laraclaw\Jobs;

use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Laraclaw\Agents\ChatBotAgent;
use Laraclaw\Approvals\ApprovalFlow;
use Laraclaw\Connectors\Connector;
use Laraclaw\DTOs\IncomingMessage;
use Laraclaw\Models\Thread;
use Laraclaw\Services\Attachments;
use Throwable;

/**
 * Queued job that runs one agent turn for a thread and delivers the reply.
 *
 * Every connector hands its messages to this job instead of queueing the agent
 * itself. Two things depend on that. The agent is built here rather than at
 * dispatch, so it reads the conversation the previous turn saved instead of the
 * one that existed when the message arrived. And the thread lock below holds for
 * the whole turn, so a second message waits for the first to finish and continues
 * the same conversation rather than starting a rival one.
 */
class RunAgentTurn implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Waiting for the lock costs an attempt but never an exception, so a real
     * failure still stops after one run instead of prompting the agent again.
     */
    public int $maxExceptions = 1;

    /**
     * Bind the thread, the message to answer, and the connector that should carry the reply.
     */
    public function __construct(
        public Thread $thread,
        public IncomingMessage $message,
        public ?Connector $connector = null,
    ) {}

    /**
     * Serialize turns on the same thread, letting a waiting turn retry rather than drop.
     *
     * The key is shared across job classes and carries no class name of its own,
     * so routines and connector messages on one thread queue behind each other,
     * and the synchronous API path can take the very same lock by name.
     */
    public function middleware(): array
    {
        return [
            new WithoutOverlapping($this->thread->lockKey())
                ->shared()
                ->withPrefix('')
                ->releaseAfter(config('laraclaw.queue.thread_lock.retry_after', 5))
                ->expireAfter(config('laraclaw.queue.thread_lock.expires_after', 900)),
        ];
    }

    /**
     * Keep retrying for as long as a turn ahead of this one could plausibly still be running.
     */
    public function retryUntil(): DateTimeInterface
    {
        return now()->addSeconds((int) config('laraclaw.queue.thread_lock.queued_wait_for', 900));
    }

    /**
     * Build the agent from the current thread state, run the turn, and reply.
     */
    public function handle(ApprovalFlow $approvals, Attachments $attachments): void
    {
        $agent = resolve(ChatBotAgent::class, ['message' => $this->message, 'thread' => $this->thread]);

        // When the agent paused on a gated tool call, this message is the user's
        // answer to it and resumes the paused run instead of starting a new turn.
        // The pause is read here, not at dispatch, so a turn that started while
        // this one was queued has already had its say.
        $decisions = $approvals->decisionsFrom($this->thread, $this->message);

        $response = $decisions
            ? $agent->prompt($decisions)
            : $agent->prompt(...$this->message->toAgentInput());

        $this->thread->update(['conversation_id' => $response->conversationId]);

        $this->replyWith()->reply(
            thread: $this->thread,
            text: $approvals->capture($this->thread, $response) ?? $response->text,
            attachments: $attachments->outbound($this->message->uuid)->getAll(),
        );
    }

    /**
     * Tell the user when a stale resume broke the turn, and log everything else.
     */
    public function failed(Throwable $exception): void
    {
        // A stale resume leaves the thread pointing at a pause the conversation
        // no longer has. Clear it, or every later message reads as an answer.
        if ($notice = resolve(ApprovalFlow::class)->recover($this->thread, $exception)) {
            $this->replyWith()->reply($this->thread, $notice);

            return;
        }

        Log::error('Laraclaw agent turn failed', [
            'connector' => $this->thread->connector->value,
            'key' => $this->thread->key,
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * Return the connector the reply goes out on.
     *
     * Email carries its own instance because the reply has to quote the subject
     * and message id of the mail being answered. Everything else is addressable
     * from the thread alone.
     */
    private function replyWith(): Connector
    {
        return $this->connector ?? $this->thread->connector();
    }
}
