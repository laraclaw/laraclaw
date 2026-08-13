<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
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

it('names a skill after its directory, not its frontmatter', function () {
    // The name in the tree is the name the model calls, so renaming the folder is
    // enough and a stale frontmatter name cannot shadow it.
    File::ensureDirectoryExists($this->skillsPath . '/todo');
    File::put($this->skillsPath . '/todo/SKILL.md', "---\nname: something-else\ndescription: Manages a todo list\n---\nTrack tasks.");

    $registry = new SkillRegistry($this->skillsPath);

    expect($registry->get('todo'))->toContain('Track tasks.')
        ->and($registry->get('something-else'))->toBeNull();
});

it('still loads a skill whose frontmatter has no description', function () {
    // This used to drop the skill without a word, so a typo made it vanish.
    Log::spy();

    File::ensureDirectoryExists($this->skillsPath . '/broken');
    File::put($this->skillsPath . '/broken/SKILL.md', "---\nname: broken\n---\n# Reconcile invoices\n\nDo the thing.");

    $registry = new SkillRegistry($this->skillsPath);

    expect($registry->get('broken'))->toContain('Do the thing.')
        ->and(collect($registry->all())->firstWhere('name', 'broken')['description'])
        ->toBe('Reconcile invoices');

    Log::shouldHaveReceived('warning')->once();
});

it('loads a skill with no frontmatter at all', function () {
    File::ensureDirectoryExists($this->skillsPath . '/bare');
    File::put($this->skillsPath . '/bare/SKILL.md', 'Just instructions, nothing else.');

    $registry = new SkillRegistry($this->skillsPath);

    expect($registry->get('bare'))->toBe('Just instructions, nothing else.')
        ->and(collect($registry->all())->firstWhere('name', 'bare')['description'])
        ->toBe('Just instructions, nothing else.');
});

it('lists the companion files bundled beside a skill', function () {
    File::ensureDirectoryExists($this->skillsPath . '/report/scripts');
    File::put($this->skillsPath . '/report/SKILL.md', "---\ndescription: Builds a report\n---\nRun the script.");
    File::put($this->skillsPath . '/report/scripts/build.sh', 'echo hi');
    File::put($this->skillsPath . '/report/reference.md', 'Column definitions.');

    $registry = new SkillRegistry($this->skillsPath);

    expect($registry->files('report'))->toBe(['reference.md', 'scripts/build.sh'])
        ->and($registry->path('report'))->toBe($this->skillsPath . '/report');
});

it('reports no companion files for a skill that is only a SKILL.md', function () {
    $registry = new SkillRegistry($this->skillsPath);

    expect($registry->files('summarise'))->toBe([]);
});
