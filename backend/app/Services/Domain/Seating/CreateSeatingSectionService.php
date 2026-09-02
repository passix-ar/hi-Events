<?php

namespace HiEvents\Services\Domain\Seating;

use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\DomainObjects\Generated\SeatDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SeatingSectionDomainObjectAbstract;
use HiEvents\DomainObjects\SeatingSectionDomainObject;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Repository\Interfaces\SeatingSectionRepositoryInterface;
use HiEvents\Repository\Interfaces\SeatRepositoryInterface;
use HiEvents\Services\Domain\Product\Exception\UnrecognizedProductIdException;
use HiEvents\Services\Domain\Seating\Exception\InvalidSeatingLayoutException;
use Illuminate\Database\DatabaseManager;

class CreateSeatingSectionService
{
    public const MAX_ROWS = 100;

    public const MAX_SEATS_PER_ROW = 100;

    public const MAX_SEATS_PER_SECTION = 2000;

    public function __construct(
        private readonly DatabaseManager $databaseManager,
        private readonly SeatingSectionRepositoryInterface $seatingSectionRepository,
        private readonly SeatRepositoryInterface $seatRepository,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly SeatGenerationService $seatGenerationService,
    ) {}

    /**
     * @throws UnrecognizedProductIdException
     * @throws InvalidSeatingLayoutException
     */
    public function createSeatingSection(
        SeatingSectionDomainObject $section,
        ?array $disabledSeats = null,
        ?array $aislePositions = null,
    ): SeatingSectionDomainObject {
        $this->validateLayout($section->getRowCount(), $section->getSeatsPerRow());
        $this->validateProduct($section->getProductId(), $section->getEventId());
        $this->validateDisabledSeats($disabledSeats, $section->getRowCount(), $section->getSeatsPerRow());
        $aislePositions = $this->normaliseAislePositions($aislePositions, $section->getSeatsPerRow());

        return $this->databaseManager->transaction(function () use ($section, $disabledSeats, $aislePositions) {
            /** @var SeatingSectionDomainObject $created */
            $created = $this->seatingSectionRepository->create([
                SeatingSectionDomainObjectAbstract::EVENT_ID => $section->getEventId(),
                SeatingSectionDomainObjectAbstract::PRODUCT_ID => $section->getProductId(),
                SeatingSectionDomainObjectAbstract::NAME => $section->getName(),
                SeatingSectionDomainObjectAbstract::ROW_COUNT => $section->getRowCount(),
                SeatingSectionDomainObjectAbstract::SEATS_PER_ROW => $section->getSeatsPerRow(),
                SeatingSectionDomainObjectAbstract::STATUS => $section->getStatus(),
                SeatingSectionDomainObjectAbstract::AISLE_POSITIONS => $aislePositions,
                SeatingSectionDomainObjectAbstract::ORDER => $this->nextOrderForEvent($section->getEventId()),
            ]);

            $this->seatRepository->insert(
                $this->seatGenerationService->buildSeatInserts($created),
            );

            if (! empty($disabledSeats)) {
                $this->seatRepository->updateWhere(
                    attributes: [SeatDomainObjectAbstract::IS_DISABLED => true],
                    where: [
                        SeatDomainObjectAbstract::SEATING_SECTION_ID => $created->getId(),
                        [SeatDomainObjectAbstract::LABEL, 'in', array_values($disabledSeats)],
                    ],
                );
            }

            return $created;
        });
    }

    /**
     * @throws InvalidSeatingLayoutException
     */
    public function validateLayout(int $rowCount, int $seatsPerRow): void
    {
        if ($rowCount < 1 || $rowCount > self::MAX_ROWS || $seatsPerRow < 1 || $seatsPerRow > self::MAX_SEATS_PER_ROW) {
            throw new InvalidSeatingLayoutException(
                __('Rows and seats per row must be between 1 and :max.', ['max' => self::MAX_ROWS])
            );
        }

        if ($rowCount * $seatsPerRow > self::MAX_SEATS_PER_SECTION) {
            throw new InvalidSeatingLayoutException(
                __('A section cannot have more than :max seats.', ['max' => self::MAX_SEATS_PER_SECTION])
            );
        }
    }

    /**
     * @throws InvalidSeatingLayoutException
     */
    public function validateDisabledSeats(?array $disabledSeats, int $rowCount, int $seatsPerRow): void
    {
        if (empty($disabledSeats)) {
            return;
        }

        $gridLabels = array_column(
            $this->seatGenerationService->generateGrid($rowCount, $seatsPerRow),
            SeatDomainObjectAbstract::LABEL,
        );

        $unknownLabels = array_diff($disabledSeats, $gridLabels);

        if (! empty($unknownLabels)) {
            throw new InvalidSeatingLayoutException(
                __('These blocked seats do not exist in the layout: :labels', [
                    'labels' => implode(', ', $unknownLabels),
                ])
            );
        }

        if (count($disabledSeats) >= $rowCount * $seatsPerRow) {
            throw new InvalidSeatingLayoutException(
                __('A section must have at least one available seat.')
            );
        }
    }

    /**
     * Aisles are stored as the seat numbers a gap is drawn after, so a gap past the last seat
     * would render nothing. Sorted and de-duplicated so the chart does not depend on input order.
     *
     * @throws InvalidSeatingLayoutException
     */
    public function normaliseAislePositions(?array $aislePositions, int $seatsPerRow): ?array
    {
        if (empty($aislePositions)) {
            return null;
        }

        $positions = array_values(array_unique(array_map('intval', $aislePositions)));
        sort($positions);

        foreach ($positions as $position) {
            if ($position < 1 || $position >= $seatsPerRow) {
                throw new InvalidSeatingLayoutException(
                    __('Aisles must sit between seat 1 and seat :max.', ['max' => $seatsPerRow - 1])
                );
            }
        }

        return $positions;
    }

    private function nextOrderForEvent(int $eventId): int
    {
        $sections = $this->seatingSectionRepository
            ->findWhere([SeatingSectionDomainObjectAbstract::EVENT_ID => $eventId]);

        if ($sections->isEmpty()) {
            return 0;
        }

        // Counting would reuse a position freed by a deleted section and tie with an existing one.
        return $sections->max(static fn (SeatingSectionDomainObject $section) => $section->getOrder()) + 1;
    }

    /**
     * @throws UnrecognizedProductIdException
     */
    public function validateProduct(int $productId, int $eventId): void
    {
        $product = $this->productRepository->findFirstWhere([
            'id' => $productId,
            'event_id' => $eventId,
        ]);

        if ($product === null) {
            throw new UnrecognizedProductIdException(
                __('Invalid product ids: :ids', ['ids' => $productId])
            );
        }

        if ($product->getProductType() !== ProductType::TICKET->name) {
            throw new UnrecognizedProductIdException(
                __('Seating sections can only be linked to ticket products.')
            );
        }
    }
}
