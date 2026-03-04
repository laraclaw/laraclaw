<?php

namespace LaraClaw\Channels\Contracts;

interface SupportsStreaming
{
    public function chunk(string $delta): void;
}
