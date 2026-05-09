<?php

namespace Laraclaw\Tests\Fixtures;

use Illuminate\Support\Collection;
use Laraclaw\Connectors\Connector;
use Laraclaw\Enums\ConnectorType;
use Laraclaw\Models\Thread;

/**
 * Minimal in-memory connector for testing. Records sent messages for assertion.
 */
class Fake extends Connector
{
    public ConnectorType $type {
        get {
            return ConnectorType::Telegram;
        }
    }

    public array $sent = [];

    public static function isDirectMessage(string $key): bool
    {
        return true;
    }

    public function reply(?Thread $thread, string $text, ?Collection $attachments = null): void
    {
        $this->sent[] = $text;
    }
}
