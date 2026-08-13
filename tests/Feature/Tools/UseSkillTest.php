<?php

use Illuminate\Support\Facades\File;
use Laraclaw\Skills\SkillRegistry;
use Laraclaw\Tools\UseSkill;
use Laravel\Ai\Tools\Request;

function skillRequest(array $data): Request
{
    return new Request($data, 'call_test');
}

beforeEach(function () {
    $this->path = sys_get_temp_dir() . '/laraclaw-skills-' . uniqid();
    File::makeDirectory($this->path);
    File::makeDirectory("{$this->path}/summarise");
    File::put("{$this->path}/summarise/SKILL.md", "---\nname: summarise\ndescription: Summarise text concisely\n---\n\nFollow these steps to summarise.\n");

    $this->tool = new UseSkill(new SkillRegistry($this->path));
});

afterEach(function () {
    File::deleteDirectory($this->path);
});

it('lists every available skill in its description', function () {
    expect((string) $this->tool->description())
        ->toContain('summarise')
        ->toContain('Summarise text concisely');
});

it('returns the skill content for a known skill', function () {
    $result = $this->tool->handle(skillRequest(['skill' => 'summarise']));

    expect((string) $result)->toContain('Follow these steps to summarise.');
});

it('returns a friendly error for an unknown skill', function () {
    $result = $this->tool->handle(skillRequest(['skill' => 'nonexistent']));

    expect((string) $result)->toContain('Unknown skill: nonexistent');
});

it('reports an empty registry in the description', function () {
    $emptyPath = sys_get_temp_dir() . '/laraclaw-skills-empty-' . uniqid();
    File::makeDirectory($emptyPath);

    $tool = new UseSkill(new SkillRegistry($emptyPath));

    expect((string) $tool->description())->toContain('No skills are currently available');

    File::deleteDirectory($emptyPath);
});

it('points the agent at the files a skill ships with', function () {
    // The instructions can name a helper script, so the agent needs the directory
    // it lives in to actually reach it.
    $path = storage_path('test-skills-companion');
    File::ensureDirectoryExists($path . '/report/scripts');
    File::put($path . '/report/SKILL.md', "---\ndescription: Builds a report\n---\nRun build.sh.");
    File::put($path . '/report/scripts/build.sh', 'echo hi');

    $result = (new UseSkill(new SkillRegistry($path)))->handle(new Request(['skill' => 'report']));

    expect($result)->toContain('Run build.sh.')
        ->toContain('scripts/build.sh')
        ->toContain($path . '/report');

    File::deleteDirectory($path);
});

it('adds no file note to a skill that is only a SKILL.md', function () {
    $path = storage_path('test-skills-bare');
    File::ensureDirectoryExists($path . '/plain');
    File::put($path . '/plain/SKILL.md', "---\ndescription: Does a thing\n---\nJust instructions.");

    $result = (new UseSkill(new SkillRegistry($path)))->handle(new Request(['skill' => 'plain']));

    expect($result)->toBe('Just instructions.');

    File::deleteDirectory($path);
});
