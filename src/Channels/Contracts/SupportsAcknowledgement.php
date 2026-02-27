<?php

namespace LaraClaw\Channels\Contracts;

interface SupportsAcknowledgement
{
    public function acknowledge(): void;
}
