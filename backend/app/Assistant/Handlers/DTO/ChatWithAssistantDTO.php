<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Handlers\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\UserDomainObject;
use Spatie\LaravelData\Attributes\DataCollectionOf;

class ChatWithAssistantDTO extends BaseDataObject
{
    /**
     * @param list<AssistantMessageDTO> $messages Oldest first; the last one is the user's new message.
     */
    public function __construct(
        public readonly UserDomainObject $user,
        public readonly int              $accountId,
        public readonly int              $organizerId,
        #[DataCollectionOf(AssistantMessageDTO::class)]
        public readonly array            $messages,
    )
    {
    }
}
