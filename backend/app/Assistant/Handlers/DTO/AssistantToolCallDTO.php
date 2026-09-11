<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Handlers\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class AssistantToolCallDTO extends BaseDataObject
{
    public function __construct(
        public readonly string $name,
        public readonly array  $arguments,
    )
    {
    }
}
