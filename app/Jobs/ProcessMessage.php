<?php

namespace App\Jobs;

use App\Channels\Contracts\ChannelDriver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        private ChannelDriver $driver,
    ) {}

    public function handle(): void
    {
        // MVP: hardcoded reply
        $this->driver->reply('pong');
    }
}
