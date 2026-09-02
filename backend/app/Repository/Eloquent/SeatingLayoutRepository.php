<?php

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\SeatingLayoutDomainObject;
use HiEvents\Models\SeatingLayout;
use HiEvents\Repository\Interfaces\SeatingLayoutRepositoryInterface;

/**
 * @extends BaseRepository<SeatingLayoutDomainObject>
 */
class SeatingLayoutRepository extends BaseRepository implements SeatingLayoutRepositoryInterface
{
    protected function getModel(): string
    {
        return SeatingLayout::class;
    }

    public function getDomainObject(): string
    {
        return SeatingLayoutDomainObject::class;
    }
}
