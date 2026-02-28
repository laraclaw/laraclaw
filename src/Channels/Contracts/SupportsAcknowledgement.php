<?php

namespace LaraClaw\Channels\Contracts;

interface SupportsAcknowledgement
{
    /**
     * Send an acknowledgement signal to the user.
     */
    public function acknowledge(): void;
}
