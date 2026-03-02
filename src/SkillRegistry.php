<?php

namespace LaraClaw;

use Illuminate\Support\Facades\File;
use League\CommonMark\Extension\FrontMatter\Data\SymfonyYamlFrontMatterParser;
use League\CommonMark\Extension\FrontMatter\FrontMatterParser;

/**
 * Loads and indexes skill definitions from SKILL.md files on disk.
 */
class SkillRegistry
{
    /** @var array<string, array{name: string, description: string, content: string}>|null */
    private ?array $skills = null;

    public function __construct(
        private string $basePath,
    ) {}

    /**
     * Return all registered skills as name/description pairs.
     *
     * @return array<int, array{name: string, description: string}>
     */
    public function all(): array
    {
        return array_values(array_map(
            fn (array $skill) => ['name' => $skill['name'], 'description' => $skill['description']],
            $this->load(),
        ));
    }

    /**
     * Return the full content of a skill by name, or null if not found.
     */
    public function get(string $name): ?string
    {
        return $this->load()[$name]['content'] ?? null;
    }

    /**
     * @return array<string, array{name: string, description: string, content: string}>
     */
    private function load(): array
    {
        if ($this->skills !== null) {
            return $this->skills;
        }

        $this->skills = [];

        if (! is_dir($this->basePath)) {
            return $this->skills;
        }

        $pattern = $this->basePath . '/*/SKILL.md';

        foreach (glob($pattern) ?: [] as $path) {
            $raw = File::get($path);
            $parsed = $this->parseFrontmatter($raw);

            if (! $parsed) {
                continue;
            }

            $this->skills[$parsed['name']] = $parsed;
        }

        return $this->skills;
    }

    /**
     * @return array{name: string, description: string, content: string}|null
     */
    private function parseFrontmatter(string $raw): ?array
    {
        $result = (new FrontMatterParser(new SymfonyYamlFrontMatterParser))->parse($raw);
        $meta = $result->getFrontMatter();

        if (empty($meta['name']) || empty($meta['description'])) {
            return null;
        }

        return [
            'name' => $meta['name'],
            'description' => $meta['description'],
            'content' => trim($result->getContent()),
        ];
    }
}
