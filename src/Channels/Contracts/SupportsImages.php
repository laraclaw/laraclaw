<?php

namespace LaraClaw\Channels\Contracts;

interface SupportsImages
{
    public function sendImage(string $disk, string $path): void;
}
