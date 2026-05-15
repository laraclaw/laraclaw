<?php

namespace Laraclaw\Console\Commands;

use Ferdiunal\Larapanda\Integrations\Ai\LarapandaAiTools;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Laraclaw\Console\Concerns\ConfiguresEnv;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\note;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

/**
 * Wizard for the headless browser tool backed by ferdiunal/larapanda (Lightpanda).
 */
class SetupBrowser extends Command
{
    use ConfiguresEnv;

    private const string COMPOSER_PACKAGE = 'ferdiunal/larapanda';

    private const string BINARY_INSTALL_URL = 'https://pkg.lightpanda.io/install.sh';

    protected $signature = 'laraclaw:setup-browser';

    protected $description = 'Set up the headless browser tool (Lightpanda via ferdiunal/larapanda)';

    /**
     * Offer to install the package and binary, then write the browser env values.
     */
    public function handle(): int
    {
        $this->heading('🕸️ Headless Browser');

        info('The browser tool lets the agent fetch and interact with JS-rendered pages via Lightpanda.');
        info('It is delivered by the ferdiunal/larapanda package, which requires PHP 8.5.');

        if (! class_exists(LarapandaAiTools::class)) {
            return $this->offerComposerInstall();
        }

        if (! confirm(label: 'Enable the browser tool?', default: $this->readEnv('LARACLAW_BROWSER_ENABLED') === 'true')) {
            $this->saveEnv(['LARACLAW_BROWSER_ENABLED' => 'false']);

            return self::SUCCESS;
        }

        $binary = $this->resolveBinaryPath();

        if ($binary !== null && ! is_executable($binary)) {
            warning("'{$binary}' does not exist or is not executable. Re-run laraclaw:setup-browser once the binary is in place.");
            $this->saveEnv(['LARACLAW_BROWSER_ENABLED' => 'false']);

            return self::FAILURE;
        }

        $env = [
            'LARACLAW_BROWSER_ENABLED' => 'true',
            'LARAPANDA_RUNTIME' => 'auto',
        ];

        if ($binary !== null) {
            $env['LARAPANDA_BINARY_PATH'] = $binary;
        }

        $this->saveEnv($env);

        info($binary !== null
            ? 'Browser tool enabled. Using the Lightpanda binary at ' . $binary . '.'
            : 'Browser tool enabled. Falling back to Docker (image: lightpanda/browser:nightly).'
        );

        return self::SUCCESS;
    }

    /**
     * Run `composer require ferdiunal/larapanda` if the user accepts, then ask them to re-run the wizard.
     *
     * Composer regenerates the autoloader on disk, but the running PHP process keeps its in-memory
     * classmap. A second wizard invocation is the cleanest way to pick up the new classes.
     */
    private function offerComposerInstall(): int
    {
        $install = confirm(
            label: 'ferdiunal/larapanda is not installed. Install it now via composer?',
            default: false,
        );

        if (! $install) {
            note('Run `composer require ' . self::COMPOSER_PACKAGE . '` yourself, then re-run laraclaw:setup-browser.');
            $this->saveEnv(['LARACLAW_BROWSER_ENABLED' => 'false']);

            return self::FAILURE;
        }

        info('Running composer require ' . self::COMPOSER_PACKAGE . '…');

        $result = Process::timeout(300)
            ->run('composer require ' . self::COMPOSER_PACKAGE, function (string $type, string $output): void {
                $this->output->write($output);
            });

        if (! $result->successful()) {
            warning('Composer require failed (exit ' . $result->exitCode() . '). Browser tool left disabled.');
            $this->saveEnv(['LARACLAW_BROWSER_ENABLED' => 'false']);

            return self::FAILURE;
        }

        info('Package installed. Re-run laraclaw:setup-browser to finish setup.');

        return self::SUCCESS;
    }

    /**
     * Resolve the Lightpanda binary path, optionally running the official install script first.
     *
     * Returns null when the user opted to skip the binary entirely (Docker fallback).
     */
    private function resolveBinaryPath(): ?string
    {
        info('Lightpanda runs as either a native binary or a Docker container.');

        $existing = $this->readEnv('LARAPANDA_BINARY_PATH') ?? '';

        if ($existing === '' && confirm(
            label: 'Install the Lightpanda binary now via the official install script?',
            default: false,
        )) {
            $installed = $this->runBinaryInstall();

            if ($installed !== null) {
                $existing = $installed;
            }
        }

        $binary = trim(text(
            label: 'Path to the Lightpanda binary (leave blank to use Docker)',
            default: $existing,
            required: false,
        ));

        return $binary === '' ? null : $binary;
    }

    /**
     * Pipe the official install script to bash, stream its output, and try to detect the resulting path.
     */
    private function runBinaryInstall(): ?string
    {
        info('Running: curl -fsSL ' . self::BINARY_INSTALL_URL . ' | bash');

        $result = Process::timeout(300)
            ->run('curl -fsSL ' . self::BINARY_INSTALL_URL . ' | bash', function (string $type, string $output): void {
                $this->output->write($output);
            });

        if (! $result->successful()) {
            warning('Install script failed (exit ' . $result->exitCode() . '). You can still type a path manually below.');

            return null;
        }

        return collect([
            '/usr/local/bin/lightpanda',
            getenv('HOME') . '/.lightpanda/bin/lightpanda',
            getenv('HOME') . '/.local/bin/lightpanda',
        ])->first(fn (string $path): bool => is_executable($path));
    }
}
