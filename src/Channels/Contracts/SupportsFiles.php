<?php

namespace LaraClaw\Channels\Contracts;

interface SupportsFiles
{
    public function sendFiles(array $files): void;
}
