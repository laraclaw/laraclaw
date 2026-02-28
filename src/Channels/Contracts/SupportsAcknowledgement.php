<?php

namespace LaraClaw\Channels\Contracts;

interface SupportsAcknowledgement
{
    /**
     * Send an acknowledgement signal to the user.
     *
     * @return void
     */
    public function acknowledge(): void;
}
