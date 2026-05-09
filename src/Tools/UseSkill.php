<?php

namespace Laraclaw\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laraclaw\Skills\SkillRegistry;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Agent tool that applies a named skill from the SkillRegistry.
 */
class UseSkill implements Tool
{
    /**
     * Inject the skill registry that holds the parsed SKILL.md catalogue.
     */
    public function __construct(private readonly SkillRegistry $registry) {}

    /**
     * Return the tool description shown to the agent, listing all available skills.
     */
    public function description(): Stringable|string
    {
        $skills = $this->registry->all();

        if ($skills === []) {
            return 'Apply a specialized skill. No skills are currently available.';
        }

        $list = collect($skills)
            ->map(fn (array $s, int $i): string => ($i + 1) . ') ' . $s['name'] . ' — ' . $s['description'])
            ->join(', ');

        return "Apply a specialized skill. Follow the returned instructions carefully. Available skills: {$list}";
    }

    /**
     * Define the input schema for this tool.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'skill' => $schema->string()->required()->description('The name of the skill to apply'),
        ];
    }

    /**
     * Look up the requested skill and return its prompt content.
     */
    public function handle(Request $request): Stringable|string
    {
        $name = $request['skill'];

        $content = $this->registry->get($name);

        if ($content === null) {
            return "Unknown skill: {$name}. Use one of the available skills listed in the tool description.";
        }

        return $content;
    }
}
