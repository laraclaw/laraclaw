<?php

use App\Ai\Skills\SkillRegistry;
use App\Ai\Tools\UseSkill;
use Laravel\Ai\Tools\Request;

function makeRegistry(array $skills = []): SkillRegistry
{
    $path = sys_get_temp_dir() . '/laraclaw-test-skills-' . getmypid();

    foreach ($skills as $name => $data) {
        $dir = $path . '/' . $name;
        \Illuminate\Support\Facades\File::ensureDirectoryExists($dir);
        \Illuminate\Support\Facades\File::put($dir . '/SKILL.md', "---\nname: {$name}\ndescription: {$data['description']}\n---\n{$data['content']}");
    }

    return new SkillRegistry($path);
}

afterEach(function () {
    $path = sys_get_temp_dir() . '/laraclaw-test-skills-' . getmypid();
    \Illuminate\Support\Facades\File::deleteDirectory($path);
});

it('includes skill names in the description', function () {
    $registry = makeRegistry([
        'greet' => ['description' => 'Greet the user', 'content' => 'Say hello.'],
        'summarize' => ['description' => 'Summarize text', 'content' => 'Condense it.'],
    ]);

    $tool = new UseSkill($registry);

    expect((string) $tool->description())
        ->toContain('greet')
        ->toContain('Greet the user')
        ->toContain('summarize')
        ->toContain('Summarize text');
});

it('returns skill content when called with a valid name', function () {
    $registry = makeRegistry([
        'greet' => ['description' => 'Greet the user', 'content' => 'Say hello warmly.'],
    ]);

    $tool = new UseSkill($registry);
    $result = $tool->handle(new Request(['skill' => 'greet']));

    expect((string) $result)->toBe('Say hello warmly.');
});

it('returns error for unknown skill', function () {
    $registry = makeRegistry();

    $tool = new UseSkill($registry);
    $result = $tool->handle(new Request(['skill' => 'nope']));

    expect((string) $result)->toContain('Unknown skill: nope');
});

it('shows no skills available when directory is empty', function () {
    $registry = makeRegistry();

    $tool = new UseSkill($registry);

    expect((string) $tool->description())->toContain('No skills are currently available');
});
