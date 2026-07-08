<?php

namespace HiEvents\Services\Domain\Seating;

use HiEvents\DomainObjects\Generated\SeatDomainObjectAbstract;
use HiEvents\DomainObjects\SeatingSectionDomainObject;

class SeatGenerationService
{
    public function labelForRowIndex(int $rowIndex): string
    {
        $label = '';
        $n = $rowIndex + 1;

        while ($n > 0) {
            $n--;
            $label = chr(65 + ($n % 26)) . $label;
            $n = intdiv($n, 26);
        }

        return $label;
    }

    /**
     * @return array<int, array{row_label: string, seat_number: int, label: string}>
     */
    public function generateGrid(int $rowCount, int $seatsPerRow): array
    {
        $grid = [];

        for ($rowIndex = 0; $rowIndex < $rowCount; $rowIndex++) {
            $rowLabel = $this->labelForRowIndex($rowIndex);
            for ($seatNumber = 1; $seatNumber <= $seatsPerRow; $seatNumber++) {
                $grid[] = [
                    SeatDomainObjectAbstract::ROW_LABEL => $rowLabel,
                    SeatDomainObjectAbstract::SEAT_NUMBER => $seatNumber,
                    SeatDomainObjectAbstract::LABEL => $rowLabel . $seatNumber,
                ];
            }
        }

        return $grid;
    }

    /**
     * @return array<int, array<string, mixed>> insert rows for every seat in the section's grid
     */
    public function buildSeatInserts(SeatingSectionDomainObject $section): array
    {
        return array_map(
            static fn(array $seat) => array_merge($seat, [
                SeatDomainObjectAbstract::EVENT_ID => $section->getEventId(),
                SeatDomainObjectAbstract::SEATING_SECTION_ID => $section->getId(),
            ]),
            $this->generateGrid($section->getRowCount(), $section->getSeatsPerRow()),
        );
    }
}
