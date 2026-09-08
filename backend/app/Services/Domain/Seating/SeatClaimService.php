<?php

namespace HiEvents\Services\Domain\Seating;

use HiEvents\DomainObjects\Generated\SeatDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\SeatingSectionDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\SeatingSectionDomainObject;
use HiEvents\DomainObjects\Status\SeatingSectionStatus;
use HiEvents\Repository\Interfaces\SeatingSectionRepositoryInterface;
use HiEvents\Repository\Interfaces\SeatRepositoryInterface;
use HiEvents\Services\Application\Handlers\Order\DTO\ProductOrderDetailsDTO;
use HiEvents\Services\Domain\Seating\Exception\SeatsUnavailableException;
use Illuminate\Support\Collection;

class SeatClaimService
{
    public function __construct(
        private readonly SeatRepositoryInterface $seatRepository,
        private readonly SeatingSectionRepositoryInterface $seatingSectionRepository,
    ) {}

    /**
     * Atomically claims the requested seats for a freshly created order. Must be called inside
     * the order-creation transaction, while the per-event advisory lock is held.
     *
     * @param  Collection<ProductOrderDetailsDTO>  $productsOrderDetails
     *
     * @throws SeatsUnavailableException
     */
    public function claimSeatsForOrder(OrderDomainObject $order, Collection $productsOrderDetails): void
    {
        $seatIdsByProduct = $productsOrderDetails
            ->filter(static fn (ProductOrderDetailsDTO $product) => ! empty($product->seat_ids))
            ->mapWithKeys(static fn (ProductOrderDetailsDTO $product) => [$product->product_id => $product->seat_ids]);

        if ($seatIdsByProduct->isEmpty()) {
            return;
        }

        $sections = $this->seatingSectionRepository->findWhere([
            SeatingSectionDomainObjectAbstract::EVENT_ID => $order->getEventId(),
            SeatingSectionDomainObjectAbstract::STATUS => SeatingSectionStatus::ACTIVE->name,
        ]);

        foreach ($seatIdsByProduct as $productId => $seatIds) {
            $sectionIds = $sections
                ->filter(static fn (SeatingSectionDomainObject $section) => $section->getProductId() === $productId)
                ->map(static fn (SeatingSectionDomainObject $section) => $section->getId())
                ->toArray();

            $claimed = empty($sectionIds) ? 0 : $this->seatRepository->claimSeats(
                orderId: $order->getId(),
                eventId: $order->getEventId(),
                seatIds: $seatIds,
                sectionIds: $sectionIds,
            );

            if ($claimed !== count($seatIds)) {
                throw new SeatsUnavailableException(
                    __('One or more of your selected seats are no longer available. Please choose different seats.')
                );
            }
        }
    }

    public function releaseSeatsForOrder(int $orderId): void
    {
        $this->seatRepository->updateWhere(
            attributes: [
                SeatDomainObjectAbstract::ORDER_ID => null,
                SeatDomainObjectAbstract::ATTENDEE_ID => null,
            ],
            where: [
                SeatDomainObjectAbstract::ORDER_ID => $orderId,
            ],
        );
    }

    public function releaseSeatForAttendee(int $attendeeId): void
    {
        $this->seatRepository->updateWhere(
            attributes: [
                SeatDomainObjectAbstract::ORDER_ID => null,
                SeatDomainObjectAbstract::ATTENDEE_ID => null,
            ],
            where: [
                SeatDomainObjectAbstract::ATTENDEE_ID => $attendeeId,
            ],
        );
    }
}
