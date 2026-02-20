<?php

namespace LaraClaw;

use Illuminate\Support\Facades\File;
use Symfony\Component\Yaml\Yaml;

class SkillRegistry
{
    /** @var array<string, array{name: string, description: string, content: string}>|null */
    private ?array $skills = null;

    public function __construct(
        private string $basePath,
    ) {}

    /**
     * @return array<int, array{name: string, description: string}>
     */
    public function all(): array
    {
        return array_values(array_map(
            fn (array $skill) => ['name' => $skill['name'], 'description' => $skill['description']],
            $this->load(),
        ));
    }

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

        $pattern = $this->basePath.'/*/SKILL.md';

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
        if (! preg_match('/\A---\s*\n(.+?)\n---\s*\n(.*)\z/s', $raw, $matches)) {
            return null;
        }

        $meta = Yaml::parse($matches[1]);

        if (empty($meta['name']) || empty($meta['description'])) {
            return null;
        }

        return [
            'name' => $meta['name'],
            'description' => $meta['description'],
            'content' => trim($matches[2]),
        ];
    }
}
