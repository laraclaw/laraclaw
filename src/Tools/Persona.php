<?php

namespace LaraClaw\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\File;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class Persona implements Tool
{
    public function description(): Stringable|string
    {
        $available = collect($this->availablePersonas());

        $list = $available->isEmpty()
            ? 'No persona files found.'
            : 'Available: '.$available->join(', ');

        return "Manage the bot persona for this conversation. {$list}. Operations: list, switch, clear.";
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'operation' => $schema->string()->required()->description('The operation: list, switch, clear'),
            'persona' => $schema->string()->description('The persona name (for switch)'),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $operation = $request['operation'];

        return match ($operation) {
            'list' => $this->list(),
            'switch' => $this->switch($request['persona'] ?? null),
            'clear' => $this->clear(),
            default => "Unknown operation '{$operation}'. Available: list, switch, clear.",
        };
    }

    private function list(): string
    {
        $personas = $this->availablePersonas();

        if (empty($personas)) {
            return 'No persona files found in '.config('laraclaw.persona.path');
        }

        return 'Available personas: '.implode(', ', $personas);
    }

    private function switch(?string $persona): string
    {
        if (! $persona) {
            return 'The "persona" parameter is required for the switch operation.';
        }

        if (! in_array($persona, $this->availablePersonas(), true)) {
            return "Unknown persona '{$persona}'. Available: ".implode(', ', $this->availablePersonas());
        }

        $path = config('laraclaw.persona.path').'/'.basename($persona).'.md';

        return "Persona switched to '{$persona}'. Apply the following instructions for the rest of this conversation:\n\n".file_get_contents($path);
    }

    private function clear(): string
    {
        $default = config('laraclaw.persona.default');
        $fallback = $default ? " Falling back to default: {$default}." : '';

        return "Persona cleared.{$fallback} Revert to your default behaviour for the rest of this conversation.";
    }

    /** @return string[] */
    private function availablePersonas(): array
    {
        $path = config('laraclaw.persona.path');

        if (! is_dir($path)) {
            return [];
        }

        return collect(File::files($path))
            ->filter(fn ($file) => $file->getExtension() === 'md')
            ->map(fn ($file) => $file->getFilenameWithoutExtension())
            ->sort()
            ->values()
            ->all();
    }
}
