<?php

namespace Tests\Unit\DataTransferObjects;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * BaseDataObject extends Spatie's Data, which builds instances with ::from().
 * ::fromArray() only exists on the deprecated BaseDTO, so calling it on a
 * BaseDataObject blows up at runtime with "Call to undefined method".
 */
class BaseDataObjectUsageTest extends TestCase
{
    public function test_base_data_objects_are_never_built_with_from_array(): void
    {
        $files = $this->appFiles();
        $dataObjects = $this->classesExtendingBaseDataObject($files);

        $this->assertNotEmpty($dataObjects, 'Expected to find classes extending BaseDataObject.');

        $violations = [];

        foreach ($files as $path => $contents) {
            foreach ($dataObjects as $class) {
                if (preg_match('/\b'.preg_quote($class, '/').'::fromArray\s*\(/', $contents)) {
                    $violations[] = sprintf('%s calls %s::fromArray()', $this->relativePath($path), $class);
                }
            }
        }

        $this->assertSame([], $violations, implode(PHP_EOL, [
            'BaseDataObject subclasses must be built with ::from(), not ::fromArray():',
            ...$violations,
        ]));
    }

    /**
     * @return array<string, string> absolute path => file contents
     */
    private function appFiles(): array
    {
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(app_path(), RecursiveDirectoryIterator::SKIP_DOTS)
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $files[$file->getPathname()] = file_get_contents($file->getPathname());
            }
        }

        return $files;
    }

    /**
     * Resolves the full inheritance chain, so a class extending a class that
     * extends BaseDataObject is caught too.
     *
     * @param  array<string, string>  $files
     * @return array<int, string> short class names
     */
    private function classesExtendingBaseDataObject(array $files): array
    {
        $parents = [];

        foreach ($files as $contents) {
            if (preg_match('/\bclass\s+(\w+)\s+extends\s+(\w+)/', $contents, $matches)) {
                $parents[$matches[1]] = $matches[2];
            }
        }

        $descendants = [];

        foreach (array_keys($parents) as $class) {
            $current = $class;
            $seen = [];

            while (isset($parents[$current]) && ! isset($seen[$current])) {
                $seen[$current] = true;
                $current = $parents[$current];

                if ($current === 'BaseDataObject') {
                    $descendants[] = $class;
                    break;
                }
            }
        }

        return $descendants;
    }

    private function relativePath(string $path): string
    {
        return ltrim(str_replace(base_path(), '', $path), DIRECTORY_SEPARATOR);
    }
}
