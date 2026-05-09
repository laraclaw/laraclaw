<?php

namespace Laraclaw\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Process;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

/**
 * Agent tool for executing shell commands.
 */
class Bash implements Tool
{
    private const int DEFAULT_TIMEOUT = 30;

    private const int MAX_OUTPUT_BYTES = 100 * 1024;

    /**
     * Return the tool description shown to the agent.
     */
    public function description(): Stringable|string
    {
        return 'Execute shell commands and scripts. Returns stdout, stderr, and the exit code as JSON.';
    }

    /**
     * Define the input schema for this tool.
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'command' => $schema->string()->required()->description('The shell command or script to execute'),
            'timeout' => $schema->integer()->description('Timeout in seconds (default: 30, max: 120)'),
            'working_directory' => $schema->string()->description('Working directory for the command'),
        ];
    }

    /**
     * Run the requested shell command and return stdout, stderr, and the exit code as JSON.
     */
    public function handle(Request $request): Stringable|string
    {
        $command = $request['command'] ?? '';

        if (trim((string) $command) === '') {
            return 'The "command" parameter is required and cannot be empty.';
        }

        $timeout = min((int) ($request['timeout'] ?? self::DEFAULT_TIMEOUT), 120);
        $workingDirectory = $request['working_directory'] ?? null;

        $process = Process::timeout($timeout);

        if ($workingDirectory !== null) {
            $process = $process->path($workingDirectory);
        }

        $result = $process->run($command);

        $stdout = $result->output();
        $stderr = $result->errorOutput();

        if (strlen($stdout) > self::MAX_OUTPUT_BYTES) {
            $stdout = substr($stdout, 0, self::MAX_OUTPUT_BYTES) . "\n\n[Truncated: output exceeds 100KB]";
        }

        if (strlen($stderr) > self::MAX_OUTPUT_BYTES) {
            $stderr = substr($stderr, 0, self::MAX_OUTPUT_BYTES) . "\n\n[Truncated: stderr exceeds 100KB]";
        }

        $output = ['exit_code' => $result->exitCode()];

        if ($stdout !== '') {
            $output['stdout'] = $stdout;
        }

        if ($stderr !== '') {
            $output['stderr'] = $stderr;
        }

        if ($stdout === '' && $stderr === '') {
            $output['note'] = 'Command completed with no output.';
        }

        return json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
