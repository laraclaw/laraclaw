<?php

namespace Laraclaw\Console\Commands;

use Illuminate\Console\Command;
use Laraclaw\Console\Concerns\ConfiguresEnv;

use function Laravel\Prompts\info;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\text;

/**
 * Configure which storage disks the File Manager tool is allowed to access.
 */
class SetupFiles extends Command
{
    use ConfiguresEnv;

    protected $signature = 'laraclaw:setup-files';

    protected $description = 'Configure the Laraclaw File Manager tool';

    public function handle(): int
    {
        $this->heading('📁 File Manager');

        info("I can only access disks you explicitly allow. Select from your app's configured disks, or create a new local one.");

        $existingDisks = array_keys(config('filesystems.disks', []));
        $currentAllowed = array_filter(explode(',', $this->readEnv('LARACLAW_ALLOWED_DISKS') ?? ''));

        $selected = multiselect(
            label: 'Which disks should I have access to?',
            options: collect($existingDisks)->mapWithKeys(fn ($d): array => [$d => $d])->put('_new', '+ Create a new local disk')->all(),
            default: $currentAllowed ?: ['local'],
            required: true,
        );

        if (in_array('_new', $selected)) {
            $selected = array_values(array_filter($selected, fn ($d): bool => $d !== '_new'));
            $selected[] = $this->createLocalDisk();
        }

        $this->writeEnv('LARACLAW_ALLOWED_DISKS', implode(',', $selected));

        return self::SUCCESS;
    }

    /**
     * Prompt for a new local disk name and root path, then inject the disk into config/filesystems.php.
     */
    private function createLocalDisk(): string
    {
        $name = text(
            label: 'New disk name',
            placeholder: 'E.g. files',
            required: true,
            validate: fn (string $v): ?string => preg_match('/^[a-z][a-z0-9_]*$/', $v)
                ? null
                : 'Use lowercase letters, digits, and underscores only.',
        );

        $root = text(
            label: 'Root path (relative to storage/app)',
            placeholder: "E.g. {$name}",
            default: $name,
            required: true,
        );

        $entry = <<<PHP

        '{$name}' => [
            'driver' => 'local',
            'root'   => storage_path('app/{$root}'),
            'throw'  => false,
        ],
PHP;

        $path = config_path('filesystems.php');
        $content = preg_replace(
            '/([\'"]disks[\'"]\s*=>\s*\[)/',
            '$1' . $entry,
            file_get_contents($path),
        );

        file_put_contents($path, $content);

        info("Disk '{$name}' added to config/filesystems.php.");

        return $name;
    }
}
