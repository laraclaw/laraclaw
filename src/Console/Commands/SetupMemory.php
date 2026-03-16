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

        info('If you enable memory, I can remember your past conversations, documents, and attached files (like PDFs and images) and use them as context to better help you in future chats.');

        if (! $this->checkRequirements()) {
            return self::FAILURE;
        }

        $this->saveEnv(['LARACLAW_MEMORY_ENABLED' => 'true']);

        info('Memory enabled!');

        return self::SUCCESS;
    }

    private function checkRequirements(): bool
    {
        if (! $this->configureEmbeddingProvider()) {
            return false;
        }

        $this->checkOptionalDependency(
            'Plain text files (.txt, .md, .csv, .html)',
            true,
        );

        $this->checkOptionalDependency(
            'PDF text extraction',
            class_exists(Pdf::class),
            'spatie/pdf-to-text',
        );

        $this->checkOptionalDependency(
            'Image OCR',
            class_exists(TesseractOCR::class),
            'thiagoalessio/tesseract_ocr',
        );

        echo PHP_EOL;

        return true;
    }

    private function configureEmbeddingProvider(): bool
    {
        $current = config('ai.default_for_embeddings');

        if ($current) {
            info("  ✓ Embedding provider: {$current}");

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

        info("  ✓ Embedding provider: {$selected}");

        return true;
    }

    private function checkOptionalDependency(string $label, bool $available, ?string $package = null): void
    {
        if ($available) {
            info("  ✓ {$label}");

            return;
        }

        if ($package && confirm("  Install {$package} to enable {$label}?", default: false)) {
            spin(
                fn () => Process::run("composer require {$package}")->throw(),
                "Installing {$package}...",
            );

            info("  ✓ {$label}");
        }
    }
}
