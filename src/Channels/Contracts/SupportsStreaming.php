<?php

namespace LaraClaw\Channels\Contracts;

/**
 * Implemented by channels that support real-time chunk delivery during a streaming response.
 */
interface SupportsStreaming
{
    /**
     * Deliver the next text chunk to the channel as it arrives from the AI.
     */
    public function chunk(string $delta): void;
}
