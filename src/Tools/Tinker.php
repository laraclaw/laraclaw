<?php

namespace LaraClaw\Tools;

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

    public function description(): Stringable|string
    {
        return 'Execute PHP code in the context of your Laravel application using Tinker. Returns the output as JSON.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'code' => $schema->string()->required()->description('The PHP code to evaluate'),
        ];
    }

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
        $output = trim($buffer->fetch());

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
