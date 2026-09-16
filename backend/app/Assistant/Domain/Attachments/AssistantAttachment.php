<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Domain\Attachments;

final readonly class AssistantAttachment
{
    public function __construct(
        public string $id,
        public string $path,
        public string $mimeType,
        public string $originalName,
        public int    $sizeBytes,
    )
    {
    }
}
