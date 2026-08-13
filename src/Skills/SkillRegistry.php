<?php

namespace Laraclaw\Skills;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use League\CommonMark\Extension\FrontMatter\Data\SymfonyYamlFrontMatterParser;
use League\CommonMark\Extension\FrontMatter\FrontMatterParser;

/**
 * Loads and indexes skill definitions from SKILL.md files on disk.
 */
class SkillRegistry
{
    /** @var array<string, array{name: string, description: string, content: string, path: string, files: string[]}>|null */
    private ?array $skills = null;

    /**
     * Bind the directory that will be scanned for SKILL.md files on first access.
     */
    public function __construct(
        private readonly string $basePath,
    ) {}

    /**
     * Return all registered skills as name/description pairs.
     *
     * @return array<int, array{name: string, description: string}>
     */
    public function all(): array
    {
        return collect($this->load())
            ->map(fn (array $skill): array => ['name' => $skill['name'], 'description' => $skill['description']])
            ->values()
            ->all();
    }

    /**
     * Return the full content of a skill by name, or null if not found.
     */
    public function get(string $name): ?string
    {
        return $this->load()[$name]['content'] ?? null;
    }

    /**
     * Return the absolute path of a skill's directory, or null if the skill is unknown.
     */
    public function path(string $name): ?string
    {
        return $this->load()[$name]['path'] ?? null;
    }

    /**
     * Return the companion files bundled beside a skill's SKILL.md, relative to its directory.
     *
     * @return string[]
     */
    public function files(string $name): array
    {
        return $this->load()[$name]['files'] ?? [];
    }

    /**
     * Lazily scan the skills directory once per process and cache the parsed entries.
     *
     * @return array<string, array{name: string, description: string, content: string, path: string, files: string[]}>
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

        foreach (glob($this->basePath . '/*/SKILL.md') ?: [] as $path) {
            $skill = $this->parse($path);

            $this->skills[$skill['name']] = $skill;
        }

        return $this->skills;
    }

    /**
     * Read one SKILL.md into a registry entry.
     *
     * The directory name is the skill name. Deriving identity from the path rather
     * than from a frontmatter field means a skill can never go missing because of a
     * typo, and the name the author sees in the tree is the name the model calls.
     *
     * @return array{name: string, description: string, content: string, path: string, files: string[]}
     */
    private function parse(string $path): array
    {
        $directory = dirname($path);
        $name = basename($directory);

        $result = new FrontMatterParser(new SymfonyYamlFrontMatterParser)->parse(File::get($path));
        $meta = $result->getFrontMatter() ?? [];
        $content = trim($result->getContent());

        return [
            'name' => $name,
            'description' => $this->resolveDescription($meta, $content, $name),
            'content' => $content,
            'path' => $directory,
            'files' => $this->companionFiles($directory),
        ];
    }

    /**
     * Work out the routing hint the model sees when picking a skill.
     *
     * A missing description used to drop the skill on the floor without a word.
     * Falling back to the opening line keeps it reachable, and the warning tells
     * the author why their skill is being advertised badly.
     *
     * @param  array<string, mixed>  $meta
     */
    private function resolveDescription(array $meta, string $content, string $name): string
    {
        if (filled($meta['description'] ?? null)) {
            return (string) $meta['description'];
        }

        Log::warning('Skill has no description in its frontmatter', ['skill' => $name]);

        $firstLine = Str::of($content)
            ->explode("\n")
            ->map(fn (string $line): string => trim(ltrim($line, '# ')))
            ->first(fn (string $line): bool => $line !== '');

        return blank($firstLine) ? $name : Str::limit($firstLine, 150);
    }

    /**
     * List everything bundled beside SKILL.md so the agent knows the skill ships with scripts or references.
     *
     * @return string[]
     */
    private function companionFiles(string $directory): array
    {
        return collect(File::allFiles($directory))
            ->map(fn ($file): string => $file->getRelativePathname())
            ->reject(fn (string $relative): bool => $relative === 'SKILL.md')
            ->sort()
            ->values()
            ->all();
    }
}
