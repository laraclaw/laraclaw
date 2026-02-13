<?php

namespace App\Listeners;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Ai\Events\ToolInvoked;

class LogToolInvocation
{
    public function handle(ToolInvoked $event): void
    {
        $toolName = class_basename($event->tool);
        $args = json_encode($event->arguments);
        $resultPreview = Str::limit((string) $event->result, 200);

        Log::channel('laraclaw')->info("Tool invoked: {$toolName}", [
            'invocation_id' => $event->invocationId,
            'tool_invocation_id' => $event->toolInvocationId,
            'arguments' => $event->arguments,
            'result_preview' => $resultPreview,
        ]);
    }
}
