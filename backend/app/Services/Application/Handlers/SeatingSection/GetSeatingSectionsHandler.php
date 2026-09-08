<?php

namespace HiEvents\Services\Application\Handlers\SeatingSection;

use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\SeatingSectionDomainObject;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\SeatingSectionRepositoryInterface;
use HiEvents\Repository\Interfaces\SeatRepositoryInterface;
use HiEvents\Services\Application\Handlers\SeatingSection\DTO\GetSeatingSectionsDTO;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class GetSeatingSectionsHandler
{
    public function __construct(
        private readonly SeatingSectionRepositoryInterface $seatingSectionRepository,
        private readonly SeatRepositoryInterface $seatRepository,
    ) {}

    public function handle(GetSeatingSectionsDTO $dto): LengthAwarePaginator
    {
        $sections = $this->seatingSectionRepository
            ->loadRelation(new Relationship(domainObject: ProductDomainObject::class, name: 'product'))
            ->findByEventId(
                eventId: $dto->eventId,
                params: $dto->queryParams,
            );

        $seatCounts = $this->seatRepository->getSeatCountsBySection($dto->eventId);

        $sections->getCollection()->each(
            static fn (SeatingSectionDomainObject $section) => $section->setSeatCounts(
                $seatCounts[$section->getId()] ?? []
            )
        );

        return $sections;
    }
}
