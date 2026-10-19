<?php

namespace Laraclaw\Agents;

use Illuminate\Support\Facades\Log;
use Laraclaw\Approvals\ApprovalFlow;
use Laraclaw\Commands\CommandRegistry;
use Laraclaw\Connectors\Connector;
use Laraclaw\DTOs\IncomingMessage;
use Laraclaw\Models\Thread;
use Laraclaw\Services\Attachments;
use Laravel\Ai\Responses\AgentResponse;
use Throwable;

/**
 * Takes a validated inbound message the rest of the way: answers a chat command
 * on the spot, or queues the agent and delivers whatever it says when it is done.
 *
 * Every connector that hands messages to a worker goes through here, so the
 * approval handshake and the failure handling are written once.
 */
class Dispatcher
{
    /**
     * Inject the attachment writer, the command registry and the approval flow the reply depends on.
     */
    public function __construct(
        private readonly Attachments $attachments,
        private readonly CommandRegistry $commands,
        private readonly ApprovalFlow $approvals,
    ) {}

    /**
     * Queue the agent for the message and deliver its reply through the connector.
     *
     * The connector defaults to the one the thread resolves, but is resolved
     * inside the callbacks rather than up front. The callbacks are serialized
     * with the job, and a resolved connector may carry an API client that is not.
     * Email passes its own, since threading headers only exist on the raw message.
     */
    public function queue(IncomingMessage $message, Thread $thread, ?Connector $connector = null): void
    {
        if ($command = $this->commands->match($message->text ?? '')) {
            $command->handle($message, $thread);

            return;
        }

        $agent = resolve(ChatBotAgent::class, ['message' => $message, 'thread' => $thread]);

        // When the agent paused on a gated tool call, this message is the user's
        // answer to it and resumes the paused run instead of starting a new turn.
        $decisions = $this->approvals->decisionsFrom($thread, $message);

        $approvals = $this->approvals;
        $attachments = $this->attachments;

        ($decisions ? $agent->queue($decisions) : $agent->queue(...$message->toAgentInput()))
            ->then(function (AgentResponse $response) use ($thread, $message, $connector, $approvals, $attachments): void {
                $thread->update(['conversation_id' => $response->conversationId]);

                ($connector ?? $thread->connector())->reply(
                    thread: $thread,
                    text: $approvals->capture($thread, $response) ?? $response->text,
                    attachments: $attachments->outbound($message->uuid)->getAll(),
                );
            })
            ->catch(function (Throwable $e) use ($thread, $connector, $approvals): void {
                // A stale resume leaves the thread pointing at a pause the conversation
                // no longer has. Clear it, or every later message reads as an answer.
                if ($notice = $approvals->recover($thread, $e)) {
                    ($connector ?? $thread->connector())->reply($thread, $notice);

                    return;
                }

                Log::error('Agent error', ['connector' => $thread->connector->value, 'error' => $e->getMessage()]);
            });
    }
}
