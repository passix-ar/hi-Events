<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Domain\HelpDocs;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Str;

/**
 * Keyword search over the public documentation (docs.getpassix.com), shipped as
 * markdown under Resources/help-docs. No embeddings and no network calls: the
 * files are parsed into sections once and scored by term frequency, so the
 * answer is always traceable to a real page.
 */
class HelpDocsIndex
{
    private const CACHE_TTL_SECONDS = 3600;

    /** Words too common in Spanish questions to carry any signal. */
    private const STOPWORDS = [
        'de', 'la', 'el', 'en', 'y', 'a', 'los', 'las', 'un', 'una', 'unos', 'unas', 'para', 'por',
        'con', 'que', 'del', 'se', 'su', 'sus', 'al', 'como', 'es', 'son', 'mi', 'mis', 'lo', 'si',
        'no', 'mas', 'muy', 'ya', 'me', 'te', 'pero', 'cuando', 'donde', 'cual', 'cuales', 'the',
        'hacer', 'hago', 'puedo', 'puede', 'pueden', 'quiero', 'necesito', 'tengo', 'hay', 'sobre',
        'desde', 'hasta', 'este', 'esta', 'estos', 'estas', 'eso', 'esto', 'ese', 'esa', 'yo', 'tu',
        'passix', 'plataforma', 'sistema', 'favor', 'gracias', 'hola', 'decime', 'contame', 'explicame',
    ];

    /** @var list<HelpDocSection>|null */
    private ?array $sections = null;

    public function __construct(
        private readonly Cache $cache,
        private readonly string $docsPath,
        private readonly string $baseUrl,
    )
    {
    }

    /**
     * @return list<array{page: string, section: string, url: string, excerpt: string}>
     */
    public function search(string $query, int $limit = 4, int $excerptLength = 1100): array
    {
        $terms = $this->queryTerms($query);

        if ($terms === []) {
            return [];
        }

        $sections = $this->sections();
        $weights = $this->inverseDocumentFrequencies($terms, $sections);
        $scored = [];

        foreach ($sections as $section) {
            $score = 0.0;

            foreach ($terms as $term) {
                $hits = 5 * substr_count($this->normalize($section->sectionTitle), $term)
                    + 4 * substr_count($this->normalize($section->pageTitle), $term)
                    + 2 * substr_count($this->normalize($section->pageDescription), $term)
                    // Capped: a long page repeating a word must not outrank a page about it.
                    + min(substr_count($section->haystack, $term), 3);

                $score += $hits * $weights[$term];
            }

            $score *= $this->audienceWeight($section->url);

            if ($score > 0) {
                $scored[] = ['score' => $score, 'section' => $section];
            }
        }

        usort($scored, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);

        return array_map(
            fn(array $hit): array => [
                'page' => $hit['section']->pageTitle,
                'section' => $hit['section']->sectionTitle,
                'url' => $hit['section']->url,
                'excerpt' => Str::limit($hit['section']->body, $excerptLength),
            ],
            array_slice($scored, 0, $limit),
        );
    }

    /**
     * A word present in half the documentation says little about which page to
     * return; a rare one says almost everything. Without this, common nouns like
     * "entradas" drown out the term that actually identifies the topic.
     *
     * @param list<string> $terms
     * @param list<HelpDocSection> $sections
     * @return array<string, float>
     */
    private function inverseDocumentFrequencies(array $terms, array $sections): array
    {
        $total = max(count($sections), 1);
        $weights = [];

        foreach ($terms as $term) {
            $documentFrequency = 0;

            foreach ($sections as $section) {
                if (str_contains($section->haystack, $term)) {
                    $documentFrequency++;
                }
            }

            $weights[$term] = log(1 + $total / (1 + $documentFrequency));
        }

        return $weights;
    }

    /**
     * The assistant speaks to organizers, so the buyer-facing pages are a weaker
     * answer to the same question - relevant, but not the procedure they run.
     */
    private function audienceWeight(string $url): float
    {
        return str_contains($url, '/compradores/') ? 0.55 : 1.0;
    }

    /**
     * Titles of every page, so the model can tell what the documentation covers.
     *
     * @return list<array{page: string, description: string, url: string}>
     */
    public function pages(): array
    {
        $pages = [];

        foreach ($this->sections() as $section) {
            $url = Str::before($section->url, '#');
            $pages[$url] = [
                'page' => $section->pageTitle,
                'description' => $section->pageDescription,
                'url' => $url,
            ];
        }

        return array_values($pages);
    }

    /**
     * @return list<HelpDocSection>
     */
    private function sections(): array
    {
        if ($this->sections !== null) {
            return $this->sections;
        }

        $this->sections = $this->cache->remember(
            'assistant.help-docs.' . $this->fingerprint(),
            self::CACHE_TTL_SECONDS,
            fn(): array => $this->parseAll(),
        );

        return $this->sections;
    }

    private function fingerprint(): string
    {
        if (!is_dir($this->docsPath)) {
            return 'missing';
        }

        $latest = 0;
        foreach ($this->files() as $file) {
            $latest = max($latest, (int)filemtime($file));
        }

        return md5($this->docsPath . ':' . $latest . ':' . count($this->files()));
    }

    /**
     * @return list<string>
     */
    private function files(): array
    {
        if (!is_dir($this->docsPath)) {
            return [];
        }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->docsPath, \FilesystemIterator::SKIP_DOTS));

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile() && in_array($file->getExtension(), ['md', 'mdx'], true)) {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    /**
     * @return list<HelpDocSection>
     */
    private function parseAll(): array
    {
        $sections = [];

        foreach ($this->files() as $file) {
            foreach ($this->parseFile($file) as $section) {
                $sections[] = $section;
            }
        }

        return $sections;
    }

    /**
     * @return list<HelpDocSection>
     */
    private function parseFile(string $file): array
    {
        $raw = (string)file_get_contents($file);
        [$frontMatter, $content] = $this->splitFrontMatter($raw);

        $pageTitle = $frontMatter['title'] ?? Str::headline(pathinfo($file, PATHINFO_FILENAME));
        $pageDescription = $frontMatter['description'] ?? '';
        $pageUrl = $this->pageUrl($file);

        $sections = [];
        $currentTitle = $pageTitle;
        $buffer = [];

        $flush = function () use (&$sections, &$buffer, &$currentTitle, $pageTitle, $pageDescription, $pageUrl): void {
            $body = trim(implode("\n", $buffer));
            $buffer = [];

            if ($body === '') {
                return;
            }

            $anchor = $currentTitle === $pageTitle ? '' : '#' . Str::slug($currentTitle);

            $sections[] = new HelpDocSection(
                pageTitle: $pageTitle,
                pageDescription: $pageDescription,
                sectionTitle: $currentTitle,
                url: $pageUrl . $anchor,
                body: $this->cleanBody($body),
                haystack: $this->normalize($pageTitle . ' ' . $pageDescription . ' ' . $currentTitle . ' ' . $body),
            );
        };

        foreach (explode("\n", $content) as $line) {
            if (preg_match('/^#{2,3}\s+(.*)$/', trim($line), $matches) === 1) {
                $flush();
                $currentTitle = trim(strip_tags($matches[1]));
                continue;
            }

            $buffer[] = $line;
        }

        $flush();

        return $sections;
    }

    /**
     * @return array{0: array<string, string>, 1: string}
     */
    private function splitFrontMatter(string $raw): array
    {
        if (!str_starts_with($raw, '---')) {
            return [[], $raw];
        }

        $end = strpos($raw, "\n---", 3);

        if ($end === false) {
            return [[], $raw];
        }

        $block = substr($raw, 3, $end - 3);
        $content = substr($raw, $end + 4);

        $frontMatter = [];
        foreach (explode("\n", $block) as $line) {
            if (preg_match('/^([a-zA-Z_]+):\s*(.*)$/', trim($line), $matches) === 1) {
                $frontMatter[$matches[1]] = trim($matches[2], " \"'");
            }
        }

        return [$frontMatter, $content];
    }

    private function pageUrl(string $file): string
    {
        $relative = ltrim(str_replace($this->docsPath, '', $file), '/');
        $slug = preg_replace('/\.(md|mdx)$/', '', $relative) ?? $relative;

        if ($slug === 'index') {
            return rtrim($this->baseUrl, '/') . '/';
        }

        return rtrim($this->baseUrl, '/') . '/' . $slug . '/';
    }

    /**
     * Strips the bits of markdown that only add noise to the model's context.
     */
    private function cleanBody(string $body): string
    {
        $body = preg_replace('/!\[[^\]]*]\([^)]*\)/', '', $body) ?? $body;          // images
        $body = preg_replace('/^\s*:::\s*\w*\s*$/m', '', $body) ?? $body;           // starlight asides
        $body = preg_replace('/\[([^\]]+)]\(([^)]+)\)/', '$1 ($2)', $body) ?? $body; // links → text (url)
        $body = preg_replace('/\n{3,}/', "\n\n", $body) ?? $body;

        return trim($body);
    }

    /**
     * @return list<string>
     */
    private function queryTerms(string $query): array
    {
        $normalized = $this->normalize($query);
        $terms = [];

        foreach (preg_split('/\s+/', $normalized) ?: [] as $word) {
            if (strlen($word) < 3 || in_array($word, self::STOPWORDS, true)) {
                continue;
            }

            $terms[$this->stem($word)] = true;
        }

        return array_keys($terms);
    }

    /**
     * Crude Spanish stemmer: keeping the first six characters makes "escaneo"
     * match "escanear" and "reembolsos" match "reembolso" without a dictionary.
     */
    private function stem(string $word): string
    {
        if (strlen($word) >= 8) {
            return substr($word, 0, 6);
        }

        if (strlen($word) >= 6) {
            return substr($word, 0, strlen($word) - 1);
        }

        return $word;
    }

    private function normalize(string $text): string
    {
        $text = Str::lower(Str::ascii($text));

        return preg_replace('/[^a-z0-9\s]/', ' ', $text) ?? $text;
    }
}
