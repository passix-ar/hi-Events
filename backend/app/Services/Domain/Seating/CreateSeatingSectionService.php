<?php

namespace HiEvents\Services\Domain\Seating;

use HiEvents\DomainObjects\Enums\ProductType;
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
        private readonly DatabaseManager                    $databaseManager,
        private readonly SeatingSectionRepositoryInterface  $seatingSectionRepository,
        private readonly SeatRepositoryInterface            $seatRepository,
        private readonly ProductRepositoryInterface         $productRepository,
        private readonly SeatGenerationService              $seatGenerationService,
    )
    {
    }

    /**
     * @throws UnrecognizedProductIdException
     * @throws InvalidSeatingLayoutException
     */
    public function createSeatingSection(SeatingSectionDomainObject $section): SeatingSectionDomainObject
    {
        $this->validateLayout($section->getRowCount(), $section->getSeatsPerRow());
        $this->validateProduct($section->getProductId(), $section->getEventId());

        return $this->databaseManager->transaction(function () use ($section) {
            /** @var SeatingSectionDomainObject $created */
            $created = $this->seatingSectionRepository->create([
                SeatingSectionDomainObjectAbstract::EVENT_ID => $section->getEventId(),
                SeatingSectionDomainObjectAbstract::PRODUCT_ID => $section->getProductId(),
                SeatingSectionDomainObjectAbstract::NAME => $section->getName(),
                SeatingSectionDomainObjectAbstract::ROW_COUNT => $section->getRowCount(),
                SeatingSectionDomainObjectAbstract::SEATS_PER_ROW => $section->getSeatsPerRow(),
                SeatingSectionDomainObjectAbstract::STATUS => $section->getStatus(),
            ]);

            $this->seatRepository->insert(
                $this->seatGenerationService->buildSeatInserts($created),
            );

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
