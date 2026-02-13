<?php

namespace App\Ai\Tools;

use App\Ai\Skills\SkillRegistry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

class UseSkill implements Tool
{
    public function __construct(
        private SkillRegistry $registry,
    ) {}

    public function description(): Stringable|string
    {
        $skills = $this->registry->all();

        if (empty($skills)) {
            return 'Apply a specialized skill. No skills are currently available.';
        }

        $list = collect($skills)
            ->map(fn (array $s, int $i) => ($i + 1) . ') ' . $s['name'] . ' — ' . $s['description'])
            ->join(', ');

        return "Apply a specialized skill. Follow the returned instructions carefully. Available skills: {$list}";
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'skill' => $schema->string()->required()->description('The name of the skill to apply'),
        ];
    }

    public function handle(Request $request): Stringable|string
    {
        $name = $request['skill'];

        $content = $this->registry->get($name);

        if ($content === null) {
            Log::warning('UseSkill: unknown skill requested', ['skill' => $name]);

            return "Unknown skill: {$name}. Use one of the available skills listed in the tool description.";
        }

        Log::info('UseSkill: applying skill', ['skill' => $name]);

        return $content;
    }
}
