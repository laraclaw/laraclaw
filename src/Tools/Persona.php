<?php

namespace Laraclaw\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\File;
use Laraclaw\Models\Thread;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Agent tool for listing, switching, and clearing the active persona for a conversation.
 */
class Persona implements Tool
{
    public function __construct(private readonly ?Thread $thread = null) {}

    /**
     * Return the tool description shown to the agent, listing all available personas.
     */
    public function description(): Stringable|string
    {
        $available = collect($this->availablePersonas());

        $list = $available->isEmpty()
            ? 'No persona files found.'
            : 'Available: ' . $available->join(', ');

        return "Manage the bot persona for this conversation. {$list}. Operations: list, switch, clear.";
    }

    /**
     * Define the input schema for this tool.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'operation' => $schema->string()->required()->description('The operation: list, switch, clear'),
            'persona' => $schema->string()->description('The persona name (for switch)'),
        ];
    }

    /**
     * Route the operation to list, switch, or clear.
     */
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

    /**
     * Return a string listing all available persona names.
     */
    private function list(): string
    {
        $personas = $this->availablePersonas();

        if ($personas === []) {
            return 'No persona files found in ' . config('laraclaw.personas.path');
        }

        return 'Available personas: ' . implode(', ', $personas);
    }

    /**
     * Persist the given persona name to the conversation record.
     */
    private function switch(?string $persona): string
    {
        if (! $persona) {
            return 'The "persona" parameter is required for the switch operation.';
        }

        if (! in_array($persona, $this->availablePersonas(), true)) {
            return "Unknown persona '{$persona}'. Available: " . implode(', ', $this->availablePersonas());
        }

        $this->thread?->update(['persona' => $persona]);

        return "Persona switched to '{$persona}'.";
    }

    /**
     * Remove the active persona from the conversation record.
     */
    private function clear(): string
    {
        $this->thread?->update(['persona' => null]);

        $default = config('laraclaw.personas.default');
        $fallback = $default ? " Falling back to default: {$default}." : '';

        return "Persona cleared.{$fallback} Revert to your default behaviour for the rest of this conversation.";
    }

    /**
     * Return the names of all persona files found in the configured personas directory.
     *
     * @return string[]
     */
    private function availablePersonas(): array
    {
        $path = config('laraclaw.personas.path');

        if (! is_dir($path)) {
            return [];
        }

        return collect(File::files($path))
            ->filter(fn ($file): bool => $file->getExtension() === 'md')
            ->map(fn ($file): string => $file->getFilenameWithoutExtension())
            ->sort()
            ->values()
            ->all();
    }
}
