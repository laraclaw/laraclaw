<?php

namespace LaraClaw\Channels\Contracts;

interface SupportsPhotos
{
    public function sendPhoto(string $disk, string $path): void;
}
