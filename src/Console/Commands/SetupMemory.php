<?php

namespace LaraClaw\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use LaraClaw\Console\Concerns\ConfiguresEnv;
use Laravel\Ai\Ai;
use Laravel\Ai\Contracts\Providers\EmbeddingProvider;
use Spatie\PdfToText\Pdf;
use thiagoalessio\TesseractOCR\TesseractOCR;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\warning;

/**
 * Configure long term memory for LaraClaw.
 */
class SetupMemory extends Command
{
    use ConfiguresEnv;

    protected $signature = 'laraclaw:setup-memory';

    protected $description = 'Configures long term memory';

    public function handle(): int
    {
        $this->heading('📒 Memory');

        $memoryEnabled = $this->readEnv('LARACLAW_MEMORY_ENABLED') === 'true';

        info('By default, I only remember the current conversation. If you enable memory, I can also remember past conversations and optionally read text from attachments, which helps me give better answers in the future.');

        if (! confirm('Enable memory?', default: $memoryEnabled)) {
            $this->saveEnv(['LARACLAW_MEMORY_ENABLED' => 'false']);

            return self::SUCCESS;
        }

        if (! $this->checkRequirements()) {
            return self::FAILURE;
        }

        $this->saveEnv(['LARACLAW_MEMORY_ENABLED' => 'true']);

        return self::SUCCESS;
    }

    private function checkRequirements(): bool
    {
        if (! $this->configureEmbeddingProvider()) {
            return false;
        }

        $this->installExtractionDependencies();

        return true;
    }

    private function installExtractionDependencies(): void
    {
        info('Great! I can also extract text from attachments and make it part of my memory.');

        $options = collect();

        if (! class_exists(Pdf::class)) {
            $options->put('pdf', 'Extract text from PDFs (will install spatie/pdf-to-text)');
        }

        if (! class_exists(TesseractOCR::class)) {
            $options->put('ocr', 'Extract text from images (will install thiagoalessio/tesseract_ocr)');
        }

        if ($options->isEmpty()) {
            info('All extraction dependencies are already installed.');

            return;
        }

        $selected = multiselect(
            label: 'I should...',
            options: $options->all(),
            required: false,
        );

        $packages = collect($selected)->map(fn (string $key): string => match ($key) {
            'pdf' => 'spatie/pdf-to-text',
            'ocr' => 'thiagoalessio/tesseract_ocr',
        });

        $packages->each(fn (string $package): mixed => spin(
            fn () => Process::run("composer require {$package}")->throw(),
            "Installing {$package}...",
        ));
    }

    private function configureEmbeddingProvider(): bool
    {
        $current = config('ai.default_for_embeddings');

        if ($current) {
            return true;
        }

        $providers = collect(config('ai.providers', []))
            ->filter(fn (array $config, string $name): bool => Ai::provider($name) instanceof EmbeddingProvider)
            ->keys()
            ->all();

        if ($providers === []) {
            warning('  ✗ No embedding providers configured in config/ai.php');
            warning('    Memory requires an embedding provider. Add one to config/ai.php and try again.');

            return false;
        }

        $selected = select(
            label: 'Which provider should generate embeddings?',
            options: $providers,
        );

        $this->writeEnv('AI_DEFAULT_FOR_EMBEDDINGS', $selected);

        return true;
    }
}
