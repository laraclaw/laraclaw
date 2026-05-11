<?php

namespace Laraclaw\Tools;

use Illuminate\Console\OutputStyle;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Artisan;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Agent tool for evaluating PHP code via Laravel Tinker.
 */
class Tinker implements Tool
{
    private const int MAX_OUTPUT_BYTES = 100 * 1024;

    /**
     * Return the tool description shown to the agent.
     */
    public function description(): Stringable|string
    {
        return 'Evaluate PHP in the context of the running Laravel app via Tinker. The full app is booted, so you can use Eloquent models, the container, config, cache, etc. Shell commands are available via `Process::run("...")`. Returns the buffered output and exit code as JSON.';
    }

    /**
     * Define the input schema for this tool.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'code' => $schema->string()->required()->description('The PHP code to evaluate'),
        ];
    }

    /**
     * Run the supplied PHP via Tinker and return the buffered output and exit code as JSON.
     */
    public function handle(Request $request): Stringable|string
    {
        $code = (string) ($request['code'] ?? '');

        if (trim($code) === '') {
            return 'The "code" parameter is required and cannot be empty.';
        }

        if (! Artisan::has('tinker')) {
            return 'The Tinker command is not registered. Make sure laravel/tinker is installed.';
        }

        $buffer = new BufferedOutput;
        $exitCode = Artisan::call('tinker', ['--execute' => $code], new OutputStyle(new ArrayInput([]), $buffer));
        $output = rtrim($buffer->fetch(), "\n");

        if (strlen($output) > self::MAX_OUTPUT_BYTES) {
            $output = substr($output, 0, self::MAX_OUTPUT_BYTES) . "\n\n[Truncated: output exceeds 100KB]";
        }

        $result = ['exit_code' => $exitCode];

        if ($output !== '') {
            $result['output'] = $output;
        } else {
            $result['note'] = 'Code executed with no output.';
        }

        return json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
