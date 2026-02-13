<?php

namespace App\Providers;

use App\Ai\Skills\SkillRegistry;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(SkillRegistry::class, function () {
            return new SkillRegistry(base_path('.agents/skills'));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
