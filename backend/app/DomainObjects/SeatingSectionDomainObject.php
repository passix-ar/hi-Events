<?php

namespace HiEvents\DomainObjects;

use HiEvents\DomainObjects\Interfaces\IsSortable;
use HiEvents\DomainObjects\SortingAndFiltering\AllowedSorts;
use Illuminate\Support\Collection;

class SeatingSectionDomainObject extends Generated\SeatingSectionDomainObjectAbstract implements IsSortable
{
    public ?Collection $seats = null;

    public ?ProductDomainObject $product = null;

    public static function getDefaultSort(): string
    {
        return static::CREATED_AT;
    }

    public static function getDefaultSortDirection(): string
    {
        return 'desc';
    }

    public static function getAllowedSorts(): AllowedSorts
    {
        return new AllowedSorts(
            [
                self::NAME => [
                    'asc' => __('Name A-Z'),
                    'desc' => __('Name Z-A'),
                ],
                self::CREATED_AT => [
                    'asc' => __('Oldest first'),
                    'desc' => __('Newest first'),
                ],
                self::UPDATED_AT => [
                    'asc' => __('Updated oldest first'),
                    'desc' => __('Updated newest first'),
                ],
            ]
        );
    }

    public function getSeats(): ?Collection
    {
        return $this->seats;
    }

    public function setSeats(?Collection $seats): static
    {
        $this->seats = $seats;

        return $this;
    }

    public function getProduct(): ?ProductDomainObject
    {
        return $this->product;
    }

    public function setProduct(?ProductDomainObject $product): static
    {
        $this->product = $product;

        return $this;
    }

    public function getTotalSeatCount(): int
    {
        return $this->getRowCount() * $this->getSeatsPerRow();
    }

    /** @var array<string, int>|null seat counts keyed by state name (AVAILABLE|HELD|SOLD) */
    public ?array $seatCounts = null;

    public function getSeatCounts(): ?array
    {
        return $this->seatCounts;
    }

    public function setSeatCounts(?array $seatCounts): static
    {
        $this->seatCounts = $seatCounts;

        return $this;
    }
}
