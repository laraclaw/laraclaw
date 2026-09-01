<?php

namespace Laraclaw\Enums;

/**
 * The outcome of trying to claim an incoming email for processing.
 */
enum EmailClaim: string
{
    case Granted = 'granted';
    case InFlight = 'in_flight';
    case AlreadyHandled = 'already_handled';
    case Exhausted = 'exhausted';

    /**
     * Check if the mail server should stop offering this message.
     *
     * A message another process is working on stays unseen, because that
     * process may still fail and we would be throwing the email away for it.
     */
    public function shouldMarkSeen(): bool
    {
        return match ($this) {
            self::AlreadyHandled, self::Exhausted => true,
            self::Granted, self::InFlight => false,
        };
    }
}
