<?php

use Illuminate\Support\Facades\File;
use Laraclaw\Skills\SkillRegistry;
use Laraclaw\Tools\UseSkill;
use Laravel\Ai\Tools\Request;

function skillRequest(array $data): Request
{
    $mock = Mockery::mock(Request::class);
    $mock->allows('offsetGet')->andReturnUsing(fn ($key) => $data[$key] ?? null);
    $mock->allows('offsetExists')->andReturnUsing(fn ($key) => array_key_exists($key, $data));

    return $mock;
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
