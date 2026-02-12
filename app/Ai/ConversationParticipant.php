<?php

namespace App\Ai;

class ConversationParticipant
{
    public readonly int $id;

    public function __construct(string $key)
    {
        $this->id = abs(crc32($key));
    }
}
