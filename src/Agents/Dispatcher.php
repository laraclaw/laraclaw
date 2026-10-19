<?php

namespace Laraclaw\Agents;

use Laraclaw\Commands\CommandRegistry;
use Laraclaw\Connectors\Connector;
use Laraclaw\DTOs\IncomingMessage;
use Laraclaw\Jobs\RunAgentTurn;
use Laraclaw\Models\Thread;

/**
 * Takes a validated inbound message the rest of the way: answers a chat command
 * on the spot, or hands the turn to the queue.
 *
 * Every connector that hands messages to a worker goes through here, so the
 * command check and the handoff to the job are written once.
 */
class Dispatcher
{
    /**
     * Inject the command registry that answers chat commands inline.
     */
    public function __construct(
        private readonly CommandRegistry $commands,
    ) {}

    /**
     * Answer a command inline, or queue one agent turn for the message.
     *
     * The agent is built inside the job rather than here, so it reads the
     * conversation the previous turn saved, and the job holds a lock on the
     * thread so two messages sent at once run one after the other.
     *
     * The connector defaults to the one the thread resolves. Email passes its
     * own, since the reply has to quote the subject and message id of the mail
     * being answered, and only the raw message knows them.
     */
    public function queue(IncomingMessage $message, Thread $thread, ?Connector $connector = null): void
    {
        if ($command = $this->commands->match($message->text ?? '')) {
            $command->handle($message, $thread);

            return;
        }

        RunAgentTurn::dispatch($thread, $message, $connector);
    }
}
