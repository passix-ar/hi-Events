<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Domain;

use HiEvents\DomainObjects\SeatingSectionDomainObject;

/**
 * The pixel geometry of the seating designer, mirrored from
 * frontend/src/utilites/seatingPlan.ts so the assistant lays sections out on
 * the same canvas the panel and the public page draw. Change a seat there and
 * change it here.
 */
final class SeatingPlanGeometry
{
    public const SEAT = 22;
    public const SEAT_GAP = 3;
    public const ROW_GAP = 4;
    public const ROW_LABEL = 20;
    public const AISLE = 14;
    public const NAME_LINE = 26;
    public const NAME_CHAR = 7.2;
    public const STAGE_WIDTH = 220;
    public const STAGE_HEIGHT = 34;
    public const GAP = 40;
    public const GRID = 20;

    /** @return array{width: int, height: int} */
    public static function sectionSize(SeatingSectionDomainObject $section): array
    {
        $seats = $section->getSeatsPerRow();
        $aisles = is_array($section->getAislePositions()) ? count($section->getAislePositions()) : 0;
        $grid = self::ROW_LABEL * 2 + $seats * self::SEAT + ($seats - 1) * self::SEAT_GAP + $aisles * self::AISLE;

        return [
            'width' => (int)max($grid, ceil(mb_strlen($section->getName()) * self::NAME_CHAR)),
            'height' => self::NAME_LINE + $section->getRowCount() * self::SEAT + ($section->getRowCount() - 1) * self::ROW_GAP,
        ];
    }

    /**
     * Rows from the stage back, each row left to right, every row centred under
     * the stage. Coordinates are non-negative like the designer expects.
     *
     * @param list<list<SeatingSectionDomainObject>> $rows
     * @return array{stage: array{x: int, y: int}, sections: array<int, array{x: int, y: int}>} keyed by section id
     */
    public static function layoutRows(array $rows): array
    {
        $rowWidths = array_map(
            static fn(array $row): int => array_sum(array_map(static fn($s): int => self::sectionSize($s)['width'], $row)) + self::GAP * (count($row) - 1),
            $rows,
        );
        $planWidth = max([self::STAGE_WIDTH, ...$rowWidths]);

        $positions = [];
        $y = self::STAGE_HEIGHT + self::GAP;
        foreach ($rows as $index => $row) {
            $rowHeight = max(array_map(static fn($s): int => self::sectionSize($s)['height'], $row));
            $x = (int)round(($planWidth - $rowWidths[$index]) / 2);
            foreach ($row as $section) {
                $size = self::sectionSize($section);
                $positions[$section->getId()] = [
                    'x' => self::snap($x),
                    'y' => self::snap($y + intdiv($rowHeight - $size['height'], 2)),
                ];
                $x += $size['width'] + self::GAP;
            }
            $y += $rowHeight + self::GAP;
        }

        return [
            'stage' => ['x' => self::snap((int)round(($planWidth - self::STAGE_WIDTH) / 2)), 'y' => 0],
            'sections' => $positions,
        ];
    }

    private static function snap(int $value): int
    {
        return max(0, (int)round($value / self::GRID) * self::GRID);
    }
}
