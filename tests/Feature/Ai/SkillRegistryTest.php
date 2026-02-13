<?php

use App\Ai\Skills\SkillRegistry;
use Illuminate\Support\Facades\File;

function skillsPath(): string
{
    return sys_get_temp_dir() . '/laraclaw-test-skills-' . getmypid();
}

function writeSkill(string $name, string $content): void
{
    $dir = skillsPath() . '/' . $name;
    File::ensureDirectoryExists($dir);
    File::put($dir . '/SKILL.md', $content);
}

afterEach(function () {
    File::deleteDirectory(skillsPath());
});

it('discovers skills from the directory', function () {
    writeSkill('greet', "---\nname: greet\ndescription: Greet the user\n---\nSay hello warmly.");
    writeSkill('summarize', "---\nname: summarize\ndescription: Summarize text\n---\nCondense the text.");

    $registry = new SkillRegistry(skillsPath());

    expect($registry->all())->toHaveCount(2)
        ->and(collect($registry->all())->pluck('name')->sort()->values()->all())->toBe(['greet', 'summarize']);
});

it('returns skill content by name', function () {
    writeSkill('greet', "---\nname: greet\ndescription: Greet the user\n---\nSay hello warmly.");

    $registry = new SkillRegistry(skillsPath());

    expect($registry->get('greet'))->toBe('Say hello warmly.');
});

it('returns null for non-existent skill', function () {
    $registry = new SkillRegistry(skillsPath());

    expect($registry->get('nope'))->toBeNull();
});

it('checks skill existence', function () {
    writeSkill('greet', "---\nname: greet\ndescription: Greet the user\n---\nSay hello.");

    $registry = new SkillRegistry(skillsPath());

    expect($registry->exists('greet'))->toBeTrue()
        ->and($registry->exists('nope'))->toBeFalse();
});

it('skips files without valid frontmatter', function () {
    writeSkill('bad', 'No frontmatter here, just text.');
    writeSkill('incomplete', "---\nname: incomplete\n---\nMissing description.");
    writeSkill('good', "---\nname: good\ndescription: A good skill\n---\nDo the thing.");

    $registry = new SkillRegistry(skillsPath());

    expect($registry->all())->toHaveCount(1)
        ->and($registry->all()[0]['name'])->toBe('good');
});

it('returns empty array when no skills exist', function () {
    $registry = new SkillRegistry(skillsPath());

    expect($registry->all())->toBe([]);
});

it('caches skills after first load', function () {
    writeSkill('greet', "---\nname: greet\ndescription: Greet the user\n---\nSay hello.");

    $registry = new SkillRegistry(skillsPath());

    $first = $registry->all();

    writeSkill('extra', "---\nname: extra\ndescription: Extra skill\n---\nExtra.");

    expect($registry->all())->toBe($first);
});
