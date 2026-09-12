<?php

declare(strict_types=1);

namespace Tests\Unit\Assistant\HelpDocs;

use HiEvents\Assistant\Domain\HelpDocs\HelpDocsIndex;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Tests\TestCase;

/**
 * Runs against the documentation that actually ships with the repo, so a page
 * being renamed or removed shows up here rather than as a vague answer.
 */
class HelpDocsIndexTest extends TestCase
{
    private function index(): HelpDocsIndex
    {
        return new HelpDocsIndex(
            cache: new Repository(new ArrayStore()),
            docsPath: (string)config('assistant.help_docs.path'),
            baseUrl: 'https://docs.getpassix.com',
        );
    }

    public function test_the_shipped_documentation_is_indexable(): void
    {
        $pages = $this->index()->pages();

        $this->assertGreaterThan(20, count($pages));

        foreach ($pages as $page) {
            $this->assertNotSame('', $page['page']);
            $this->assertStringStartsWith('https://docs.getpassix.com/', $page['url']);
        }
    }

    /**
     * @dataProvider realQuestions
     */
    public function test_real_questions_reach_the_expected_page(string $question, string $expectedUrlFragment): void
    {
        $results = $this->index()->search($question);

        $this->assertNotEmpty($results, sprintf('No match for "%s"', $question));

        $urls = array_column($results, 'url');
        $matched = array_filter($urls, static fn(string $url): bool => str_contains($url, $expectedUrlFragment));

        $this->assertNotEmpty(
            $matched,
            sprintf('"%s" returned %s, expected something under %s', $question, implode(', ', $urls), $expectedUrlFragment),
        );
    }

    public static function realQuestions(): array
    {
        return [
            'refunds' => ['¿cómo hago un reembolso?', '/ventas/reembolsos/'],
            'refunds plural' => ['quiero hacer reembolsos a varios compradores', '/ventas/reembolsos/'],
            'check in scanning' => ['cómo escaneo las entradas en la puerta', '/check-in/'],
            'mercadopago' => ['cómo conecto mi cuenta de MercadoPago para cobrar', '/pagos/'],
            'team' => ['quiero invitar a alguien de mi equipo al panel', '/organizacion/equipo/'],
            'widget' => ['cómo pongo el widget de venta en mi web', '/tecnico/widget/'],
        ];
    }

    public function test_results_carry_an_excerpt_and_a_section_url(): void
    {
        $results = $this->index()->search('reembolso');

        $this->assertNotEmpty($results);
        $this->assertSame(['page', 'section', 'url', 'excerpt'], array_keys($results[0]));
        $this->assertNotSame('', $results[0]['excerpt']);
    }

    public function test_a_query_with_only_stopwords_returns_nothing(): void
    {
        $this->assertSame([], $this->index()->search('hola como estas'));
    }

    public function test_excerpts_are_stripped_of_markdown_noise(): void
    {
        foreach ($this->index()->search('reembolso', limit: 6) as $result) {
            $this->assertStringNotContainsString('![', $result['excerpt']);
            $this->assertDoesNotMatchRegularExpression('/^:::/m', $result['excerpt']);
        }
    }

    public function test_the_limit_is_respected(): void
    {
        $this->assertLessThanOrEqual(2, count($this->index()->search('entradas', limit: 2)));
    }
}
