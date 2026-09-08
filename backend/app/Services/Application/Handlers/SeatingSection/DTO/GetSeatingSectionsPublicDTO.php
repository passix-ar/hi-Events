<?php

namespace HiEvents\Services\Application\Handlers\SeatingSection\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class GetSeatingSectionsPublicDTO extends BaseDataObject
{
    public function __construct(
        public int $event_id,
        public ?int $authenticated_account_id = null,
        public bool $is_super_admin = false,
    ) {}
}
