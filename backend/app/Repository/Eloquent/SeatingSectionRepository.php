<?php

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\Generated\SeatingSectionDomainObjectAbstract;
use HiEvents\DomainObjects\SeatingSectionDomainObject;
use HiEvents\Http\DTO\QueryParamsDTO;
use HiEvents\Models\SeatingSection;
use HiEvents\Repository\Interfaces\SeatingSectionRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @extends BaseRepository<SeatingSectionDomainObject>
 */
class SeatingSectionRepository extends BaseRepository implements SeatingSectionRepositoryInterface
{
    protected function getModel(): string
    {
        return SeatingSection::class;
    }

    public function getDomainObject(): string
    {
        return SeatingSectionDomainObject::class;
    }

    public function findByEventId(int $eventId, QueryParamsDTO $params): LengthAwarePaginator
    {
        $where = [
            [SeatingSectionDomainObjectAbstract::EVENT_ID, '=', $eventId],
        ];

        if (! empty($params->query)) {
            $where[] = static function (Builder $builder) use ($params) {
                $builder
                    ->where(SeatingSectionDomainObjectAbstract::NAME, 'ilike', '%'.$params->query.'%');
            };
        }

        $this->model = $this->model->orderBy(
            $this->validateSortColumn($params->sort_by, SeatingSectionDomainObject::class),
            $this->validateSortDirection($params->sort_direction, SeatingSectionDomainObject::class),
        );

        return $this->paginateWhere(
            where: $where,
            limit: $params->per_page,
            page: $params->page,
        );
    }
}
