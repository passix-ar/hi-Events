<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Console;

use FilesystemIterator;
use Illuminate\Console\Command;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The assistant answers "how do I…" questions from a copy of the public
 * documentation that ships with this repo (the docs live in passix-docs, which
 * is deployed separately). Run this after changing the docs so the copy and the
 * site do not drift apart.
 */
class SyncHelpDocsCommand extends Command
{
    protected $signature = 'assistant:sync-help-docs
                            {--from= : Path to passix-docs/src/content/docs}
                            {--dry-run : List what would change without writing}';

    protected $description = 'Copy the public documentation markdown into the assistant help-docs resources';

    public function handle(): int
    {
        $source = rtrim((string)$this->option('from'), '/');
        $destination = rtrim((string)config('assistant.help_docs.path'), '/');

        if ($source === '' || !is_dir($source)) {
            $this->error(__('Pass --from with the path to passix-docs/src/content/docs'));

            return self::FAILURE;
        }

        $dryRun = (bool)$this->option('dry-run');
        $added = $updated = $removed = 0;
        $seen = [];

        foreach ($this->markdownFiles($source) as $file) {
            $relative = ltrim(str_replace($source, '', $file), '/');
            $target = $destination . '/' . $relative;
            $seen[$relative] = true;

            $contents = (string)file_get_contents($file);
            $exists = is_file($target);

            if ($exists && (string)file_get_contents($target) === $contents) {
                continue;
            }

            $exists ? $updated++ : $added++;
            $this->line(($exists ? '~ ' : '+ ') . $relative);

            if (!$dryRun) {
                @mkdir(dirname($target), 0755, true);
                file_put_contents($target, $contents);
            }
        }

        foreach ($this->markdownFiles($destination) as $file) {
            $relative = ltrim(str_replace($destination, '', $file), '/');

            if (isset($seen[$relative])) {
                continue;
            }

            $removed++;
            $this->line('- ' . $relative);

            if (!$dryRun) {
                @unlink($file);
            }
        }

        $this->info(sprintf(
            '%s%d added, %d updated, %d removed.',
            $dryRun ? '[dry run] ' : '',
            $added,
            $updated,
            $removed,
        ));

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function markdownFiles(string $path): array
    {
        if (!is_dir($path)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && in_array($file->getExtension(), ['md', 'mdx'], true)) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }
}
