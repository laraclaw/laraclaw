<?php

namespace App\Providers;

use App\Ai\Skills\SkillRegistry;
use App\Listeners\LogAgentRequest;
use App\Listeners\LogToolInvocation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Events\AgentPrompted;
use Laravel\Ai\Events\PromptingAgent;
use Laravel\Ai\Events\ToolInvoked;

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
        Event::listen(PromptingAgent::class, function (PromptingAgent $event) {
            Log::channel('laraclaw')->info('Prompting agent', [
                'invocation_id' => $event->invocationId,
                'agent' => get_class($event->prompt->agent),
                'prompt' => $event->prompt->prompt,
            ]);
        });

        Event::listen(AgentPrompted::class, LogAgentRequest::class);
        Event::listen(ToolInvoked::class, LogToolInvocation::class);
    }
}
