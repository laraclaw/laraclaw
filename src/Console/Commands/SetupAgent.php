<?php

namespace Laraclaw\Console\Commands;

use Illuminate\Console\Command;
use Laraclaw\Console\Concerns\ConfiguresEnv;

use function Laravel\Prompts\info;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * Set or update the agent name written to LARACLAW_AGENT_NAME.
 */
class SetupAgent extends Command
{
    use ConfiguresEnv;

    protected $signature = 'laraclaw:setup-agent';

    protected $description = 'Set the Laraclaw agent name';

    public function handle(): int
    {
        $this->heading('🤖 Agent Name');

        $existing = $this->readEnv('LARACLAW_AGENT_NAME');

        if ($existing !== null && select('Agent name', [
            'existing' => "Use existing: {$existing}",
            'new' => 'Set a new name',
        ]) === 'existing') {
            return self::SUCCESS;
        }

        info("Now, it's time to give me a name!");

        $name = text('🤖 How should I be called?', placeholder: 'E.g. Jarvis', required: true);
        $this->writeEnv('LARACLAW_AGENT_NAME', $name);

        info("Great! I'll be called {$name} from now on.");

        return self::SUCCESS;
    }
}
