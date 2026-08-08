<?php

namespace Tests\Unit\Validators\Rules;

use HiEvents\Validators\Rules\SafeEmailRule;
use Tests\TestCase;

/**
 * Guards the guard: every place that validates an email must also screen it for
 * control characters, otherwise the header-injection hole reopens on that field
 * alone. Scanning the source keeps this true for rules added later, which a
 * per-endpoint test cannot do.
 */
class EmailRulesAreGuardedTest extends TestCase
{
    private const SCANNED_DIRECTORIES = [
        'app/Http/Request',
        'app/Validators',
    ];

    public function test_every_email_validation_rule_is_paired_with_safe_email(): void
    {
        $unguarded = [];

        foreach ($this->ruleDeclarations() as [$file, $line, $declaration]) {
            if ($this->declaresEmailRule($declaration) && ! str_contains($declaration, SafeEmailRule::NAME)) {
                $unguarded[] = "{$file}:{$line} → {$declaration}";
            }
        }

        $this->assertSame([], $unguarded, sprintf(
            "Estas reglas validan un email sin '%s'. Agregá la regla o el campo queda expuesto a "
            ."inyección de headers de email:\n%s",
            SafeEmailRule::NAME,
            implode("\n", $unguarded),
        ));
    }

    /**
     * @return array<array{0: string, 1: int, 2: string}>
     */
    private function ruleDeclarations(): array
    {
        $declarations = [];

        foreach (self::SCANNED_DIRECTORIES as $directory) {
            $path = base_path($directory);

            if (! is_dir($path)) {
                continue;
            }

            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path));

            /** @var \SplFileInfo $file */
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }

                foreach (file($file->getPathname()) as $index => $line) {
                    $declarations[] = [$directory.'/'.$file->getFilename(), $index + 1, trim($line)];
                }
            }
        }

        return $declarations;
    }

    /**
     * Matches a rule line that applies Laravel's `email` rule, in array syntax
     * (['required', 'email']) or pipe syntax ('required|email'), and skips the
     * REQUIRED_EMAIL constant (guarded at its definition) and this test's own
     * source.
     */
    private function declaresEmailRule(string $declaration): bool
    {
        if (! str_contains($declaration, '=>') || str_contains($declaration, 'REQUIRED_EMAIL')) {
            return false;
        }

        return (bool) preg_match("/'email'(?!\s*=>)/", $declaration)
            || (bool) preg_match("/'[^']*\|email(\||')/", $declaration);
    }
}
