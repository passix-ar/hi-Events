<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Domain\HelpDocs;

final readonly class HelpDocSection
{
    public function __construct(
        public string $pageTitle,
        public string $pageDescription,
        public string $sectionTitle,
        public string $url,
        public string $body,
        public string $haystack,
    )
    {
    }
}
