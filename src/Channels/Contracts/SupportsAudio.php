<?php

namespace LaraClaw\Channels\Contracts;

interface SupportsAudio
{
    public function sendAudio(string $filePath): void;
}
