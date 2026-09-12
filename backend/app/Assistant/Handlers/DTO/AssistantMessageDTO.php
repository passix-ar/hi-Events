<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Handlers\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class AssistantMessageDTO extends BaseDataObject
{
    public const ROLE_USER = 'user';
    public const ROLE_ASSISTANT = 'assistant';

    public function __construct(
        public readonly string $role,
        public readonly string $content,
    )
    {
    }
}
