<?php

namespace HiEvents\Services\Application\Handlers\SeatingSection\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\Http\DTO\QueryParamsDTO;

class GetSeatingSectionsDTO extends BaseDataObject
{
    public function __construct(
        public int $eventId,
        public QueryParamsDTO $queryParams,
    ) {}
}
