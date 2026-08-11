<?php

namespace Laraclaw\Connectors;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laraclaw\DTOs\IncomingMessage;
use Laraclaw\Enums\ConnectorType;
use Laraclaw\Models\Thread;
use Laravel\Ai\Approvals\PendingApproval;

use function Laraclaw\Support\markdownToAnsi;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\note;

class Terminal extends Connector
{
    public ConnectorType $type {
        get {
            return ConnectorType::Terminal;
        }
    }

    /**
     * Build an IncomingMessage from a raw Slack event payload,
     * downloading any file attachments to storage.
     */
    public static function createIncomingMessageFrom(string $input, Authenticatable $user): IncomingMessage
    {
        $uuid = (string) Str::uuid();

        return new IncomingMessage(
            text: $input,
            connector: ConnectorType::Terminal,
            key: $user->id,
            isDirectMessage: true,
            uuid: $uuid,
        );
    }

    /**
     * Terminal sessions are always direct messages.
     */
    public static function isDirectMessage(string $key): bool
    {
        return true;
    }

    /**
     * Render the response to the terminal with ANSI formatting.
     */
    public function reply(?Thread $thread, string $text, ?Collection $attachments = null): void
    {
        note(markdownToAnsi($text));
    }

    /**
     * Ask the operator to approve a paused tool call.
     */
    public function askForApproval(PendingApproval $approval): bool
    {
        return confirm('⚠️ ' . ($approval->reason ?? "Run {$approval->tool}?"));
    }
}
