<?php

namespace LaraClaw;

class PendingFileReply
{
    /** @var array<int, array{disk: string, path: string}> */
    public array $files = [];

    public function push(string $disk, string $path): void
    {
        $this->files[] = ['disk' => $disk, 'path' => $path];
    }
}
