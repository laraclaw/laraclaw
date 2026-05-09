<?php

use Illuminate\Support\Facades\File;
use Laraclaw\Skills\SkillRegistry;

beforeEach(function () {
    $this->skillsPath = storage_path('test-skills');
    File::ensureDirectoryExists($this->skillsPath . '/summarise');
    File::put($this->skillsPath . '/summarise/SKILL.md', <<<'MD'
        ---
        name: summarise
        description: Summarises text
        ---

        Summarise in 3 bullet points.
        MD);
});

afterEach(function () {
    File::deleteDirectory($this->skillsPath);
});

it('loads skills from disk', function () {
    $registry = new SkillRegistry($this->skillsPath);

    $all = $registry->all();

    expect($all)->toHaveCount(1)
        ->and($all[0]['name'])->toBe('summarise')
        ->and($all[0]['description'])->toBe('Summarises text');
});

it('returns skill content by name', function () {
    $registry = new SkillRegistry($this->skillsPath);

    expect($registry->get('summarise'))->toContain('3 bullet points');
});

it('returns null for unknown skill', function () {
    $registry = new SkillRegistry($this->skillsPath);

    expect($registry->get('nonexistent'))->toBeNull();
});

it('returns empty when base path does not exist', function () {
    $registry = new SkillRegistry('/tmp/does-not-exist-' . uniqid());

    expect($registry->all())->toBeEmpty();
});

it('skips skill files missing required frontmatter', function () {
    File::ensureDirectoryExists($this->skillsPath . '/broken');
    File::put($this->skillsPath . '/broken/SKILL.md', "---\nname: broken\n---\nNo description field.");

    $registry = new SkillRegistry($this->skillsPath);

    expect($registry->get('broken'))->toBeNull()
        ->and($registry->all())->toHaveCount(1);
});
