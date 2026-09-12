<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Handlers\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use Spatie\LaravelData\Attributes\DataCollectionOf;

class AssistantReplyDTO extends BaseDataObject
{
    /**
     * @param list<AssistantToolCallDTO> $toolCalls
     */
    public function __construct(
        public readonly string $reply,
        #[DataCollectionOf(AssistantToolCallDTO::class)]
        public readonly array  $toolCalls,
        public readonly int    $inputTokens,
        public readonly int    $outputTokens,
    )
    {
    }
}
