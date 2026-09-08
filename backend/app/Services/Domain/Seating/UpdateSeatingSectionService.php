<?php

namespace HiEvents\Services\Domain\Seating;

use HiEvents\DomainObjects\Enums\SeatState;
use HiEvents\DomainObjects\Generated\SeatDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SeatingSectionDomainObjectAbstract;
use HiEvents\DomainObjects\SeatDomainObject;
use HiEvents\DomainObjects\SeatingSectionDomainObject;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\SeatingSectionRepositoryInterface;
use HiEvents\Repository\Interfaces\SeatRepositoryInterface;
use HiEvents\Services\Domain\Product\Exception\UnrecognizedProductIdException;
use HiEvents\Services\Domain\Seating\Exception\InvalidSeatingLayoutException;
use HiEvents\Services\Domain\Seating\Exception\SeatingSectionInUseException;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class UpdateSeatingSectionService
{
    public function __construct(
        private readonly DatabaseManager $databaseManager,
        private readonly SeatingSectionRepositoryInterface $seatingSectionRepository,
        private readonly SeatRepositoryInterface $seatRepository,
        private readonly CreateSeatingSectionService $createSeatingSectionService,
        private readonly SeatGenerationService $seatGenerationService,
    ) {}

    /**
     * @throws ResourceNotFoundException
     * @throws UnrecognizedProductIdException
     * @throws InvalidSeatingLayoutException
     * @throws SeatingSectionInUseException
     */
    public function updateSeatingSection(
        SeatingSectionDomainObject $section,
        ?array $disabledSeats = null,
        ?array $aislePositions = null,
    ): SeatingSectionDomainObject {
        /** @var SeatingSectionDomainObject|null $existing */
        $existing = $this->seatingSectionRepository->findFirstWhere([
            SeatingSectionDomainObjectAbstract::ID => $section->getId(),
            SeatingSectionDomainObjectAbstract::EVENT_ID => $section->getEventId(),
        ]);

        if ($existing === null) {
            throw new ResourceNotFoundException(__('Seating section not found.'));
        }

        $this->createSeatingSectionService->validateLayout($section->getRowCount(), $section->getSeatsPerRow());
        $this->createSeatingSectionService->validateProduct($section->getProductId(), $section->getEventId());
        $this->createSeatingSectionService->validateDisabledSeats(
            $disabledSeats,
            $section->getRowCount(),
            $section->getSeatsPerRow(),
        );

        $aislePositions = $this->createSeatingSectionService->normaliseAislePositions(
            $aislePositions,
            $section->getSeatsPerRow(),
        );

        return $this->databaseManager->transaction(function () use ($existing, $section, $disabledSeats, $aislePositions) {
            $this->databaseManager->statement('SELECT pg_advisory_xact_lock(?)', [$existing->getEventId()]);

            $occupiedSeats = $this->getOccupiedSeats($existing);

            if ($section->getProductId() !== $existing->getProductId() && $occupiedSeats->isNotEmpty()) {
                throw new SeatingSectionInUseException(
                    __('The product cannot be changed while seats in this section are held or sold.')
                );
            }

            $this->validateShrink($existing, $section, $occupiedSeats);
            $this->validateDisabledAreNotOccupied($disabledSeats, $occupiedSeats);

            $this->applyGridChanges($existing, $section);
            $this->applyDisabledSeats($existing, $disabledSeats);

            /** @var SeatingSectionDomainObject $updated */
            $updated = $this->seatingSectionRepository->updateFromArray($existing->getId(), [
                SeatingSectionDomainObjectAbstract::NAME => $section->getName(),
                SeatingSectionDomainObjectAbstract::PRODUCT_ID => $section->getProductId(),
                SeatingSectionDomainObjectAbstract::ROW_COUNT => $section->getRowCount(),
                SeatingSectionDomainObjectAbstract::SEATS_PER_ROW => $section->getSeatsPerRow(),
                SeatingSectionDomainObjectAbstract::STATUS => $section->getStatus(),
                SeatingSectionDomainObjectAbstract::AISLE_POSITIONS => $aislePositions,
            ]);

            if ($section->getName() !== $existing->getName()) {
                $this->seatRepository->updateAttendeeSeatLabelsForSection(
                    sectionId: $existing->getId(),
                    sectionName: $section->getName(),
                );
            }

            return $updated;
        });
    }

    /**
     * @return Collection<int, SeatDomainObject> seats that are held or sold
     */
    private function getOccupiedSeats(SeatingSectionDomainObject $existing): Collection
    {
        return $this->seatRepository
            ->findByEventIdWithState($existing->getEventId(), [$existing->getId()])
            ->filter(static fn (SeatDomainObject $seat) => in_array(
                $seat->getState(),
                [SeatState::HELD->name, SeatState::SOLD->name],
                true,
            ));
    }

    /**
     * @throws SeatingSectionInUseException
     */
    private function validateShrink(
        SeatingSectionDomainObject $existing,
        SeatingSectionDomainObject $section,
        Collection $occupiedSeats,
    ): void {
        $removedRowLabels = $this->getRemovedRowLabels($existing, $section);

        $blockingSeat = $occupiedSeats->first(
            static fn (SeatDomainObject $seat) => in_array($seat->getRowLabel(), $removedRowLabels, true)
                || $seat->getSeatNumber() > $section->getSeatsPerRow()
        );

        if ($blockingSeat !== null) {
            throw new SeatingSectionInUseException(
                __('The section cannot be made smaller: seat :label is held or sold.', ['label' => $blockingSeat->getLabel()])
            );
        }
    }

    /**
     * @throws SeatingSectionInUseException
     */
    private function validateDisabledAreNotOccupied(?array $disabledSeats, Collection $occupiedSeats): void
    {
        if (empty($disabledSeats)) {
            return;
        }

        $blockingSeat = $occupiedSeats->first(
            static fn (SeatDomainObject $seat) => in_array($seat->getLabel(), $disabledSeats, true)
        );

        if ($blockingSeat !== null) {
            throw new SeatingSectionInUseException(
                __('Seat :label cannot be blocked because it is held or sold.', ['label' => $blockingSeat->getLabel()])
            );
        }
    }

    private function applyDisabledSeats(SeatingSectionDomainObject $existing, ?array $disabledSeats): void
    {
        if ($disabledSeats === null) {
            return;
        }

        $this->seatRepository->updateWhere(
            attributes: [SeatDomainObjectAbstract::IS_DISABLED => false],
            where: [SeatDomainObjectAbstract::SEATING_SECTION_ID => $existing->getId()],
        );

        if (! empty($disabledSeats)) {
            $this->seatRepository->updateWhere(
                attributes: [SeatDomainObjectAbstract::IS_DISABLED => true],
                where: [
                    SeatDomainObjectAbstract::SEATING_SECTION_ID => $existing->getId(),
                    [SeatDomainObjectAbstract::LABEL, 'in', array_values($disabledSeats)],
                ],
            );
        }
    }

    private function getRemovedRowLabels(SeatingSectionDomainObject $existing, SeatingSectionDomainObject $section): array
    {
        $removed = [];

        for ($rowIndex = $section->getRowCount(); $rowIndex < $existing->getRowCount(); $rowIndex++) {
            $removed[] = $this->seatGenerationService->labelForRowIndex($rowIndex);
        }

        return $removed;
    }

    private function applyGridChanges(SeatingSectionDomainObject $existing, SeatingSectionDomainObject $section): void
    {
        $removedRowLabels = $this->getRemovedRowLabels($existing, $section);
        $newSeatsPerRow = $section->getSeatsPerRow();

        if (! empty($removedRowLabels) || $newSeatsPerRow < $existing->getSeatsPerRow()) {
            $this->seatRepository->deleteWhere([
                SeatDomainObjectAbstract::SEATING_SECTION_ID => $existing->getId(),
                static function (Builder $builder) use ($removedRowLabels, $newSeatsPerRow) {
                    $builder->where(static function (Builder $query) use ($removedRowLabels, $newSeatsPerRow) {
                        $query->where(SeatDomainObjectAbstract::SEAT_NUMBER, '>', $newSeatsPerRow);

                        if (! empty($removedRowLabels)) {
                            $query->orWhereIn(SeatDomainObjectAbstract::ROW_LABEL, $removedRowLabels);
                        }
                    });
                },
            ]);
        }

        $existingSeatKeys = $this->seatRepository
            ->findWhere([SeatDomainObjectAbstract::SEATING_SECTION_ID => $existing->getId()])
            ->map(static fn (SeatDomainObject $seat) => $seat->getRowLabel().'|'.$seat->getSeatNumber())
            ->flip();

        $inserts = [];
        foreach ($this->seatGenerationService->generateGrid($section->getRowCount(), $newSeatsPerRow) as $seat) {
            $key = $seat[SeatDomainObjectAbstract::ROW_LABEL].'|'.$seat[SeatDomainObjectAbstract::SEAT_NUMBER];
            if (! isset($existingSeatKeys[$key])) {
                $inserts[] = array_merge($seat, [
                    SeatDomainObjectAbstract::EVENT_ID => $existing->getEventId(),
                    SeatDomainObjectAbstract::SEATING_SECTION_ID => $existing->getId(),
                ]);
            }
        }

        if (! empty($inserts)) {
            $this->seatRepository->insert($inserts);
        }
    }
}
