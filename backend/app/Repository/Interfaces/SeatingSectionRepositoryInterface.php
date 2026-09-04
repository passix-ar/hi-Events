<?php

namespace HiEvents\Repository\Interfaces;

use HiEvents\DomainObjects\SeatingSectionDomainObject;
use HiEvents\Http\DTO\QueryParamsDTO;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * @extends RepositoryInterface<SeatingSectionDomainObject>
 */
interface SeatingSectionRepositoryInterface extends RepositoryInterface
{
    public function findByEventId(int $eventId, QueryParamsDTO $params): LengthAwarePaginator;
}
