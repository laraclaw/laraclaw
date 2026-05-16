<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $this->requireEnv('ANTHROPIC_API_KEY');
    $this->authenticatedUser();

    Schema::dropIfExists('e2e_posts');
    Schema::create('e2e_posts', function ($table): void {
        $table->id();
        $table->string('title');
        $table->timestamps();
    });

    foreach (['Alpha', 'Beta', 'Gamma'] as $title) {
        DB::table('e2e_posts')->insert([
            'title' => $title,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    config(['laraclaw.tools.read_database.allowed_tables' => ['e2e_posts']]);
});

it('runs a read-only query and reports the row count', function (): void {
    $reply = $this->postMessage('Use ReadDatabase to count rows in the e2e_posts table and tell me the number.');

    expect($reply['success'])->toBeTrue();
    expect($reply['text'])->toContain('3');
});
