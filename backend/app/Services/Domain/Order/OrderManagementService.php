<?php

namespace HiEvents\Services\Domain\Order;

use Carbon\Carbon;
use HiEvents\DomainObjects\AffiliateDomainObject;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\MercadopagoPreferenceDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\MercadopagoPreferenceDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\PromoCodeDomainObject;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Helper\IdHelper;
use HiEvents\Repository\Interfaces\MercadopagoPreferenceRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Domain\Tax\TaxAndFeeOrderRollupService;
use Illuminate\Support\Collection;

class OrderManagementService
{
    public function __construct(
        readonly private OrderRepositoryInterface $orderRepository,
        readonly private TaxAndFeeOrderRollupService $taxAndFeeOrderRollupService,
        readonly private MercadopagoPreferenceRepositoryInterface $mercadopagoPreferenceRepository,
    ) {}

    public function deleteExistingOrders(int $eventId, string $sessionId): void
    {
        $reservedOrders = $this->orderRepository->findWhere([
            OrderDomainObjectAbstract::SESSION_ID => $sessionId,
            OrderDomainObjectAbstract::STATUS => OrderStatus::RESERVED->name,
            OrderDomainObjectAbstract::EVENT_ID => $eventId,
        ]);

        if ($reservedOrders->isEmpty()) {
            return;
        }

        $orderIds = $reservedOrders->map(fn (OrderDomainObject $order) => $order->getId())->all();

        // Never delete an order with a payment already in progress. With MercadoPago
        // Checkout Pro the buyer leaves the site to pay; if they re-enter checkout we
        // must not delete the order being paid, or the approval webhook lands on a
        // missing order and we charge without delivering a ticket.
        $protectedOrderIds = $this->mercadopagoPreferenceRepository
            ->findWhere([[MercadopagoPreferenceDomainObjectAbstract::ORDER_ID, 'in', $orderIds]])
            ->map(fn (MercadopagoPreferenceDomainObject $preference) => $preference->getOrderId())
            ->all();

        $deletableOrderIds = array_values(array_diff($orderIds, $protectedOrderIds));

        if ($deletableOrderIds === []) {
            return;
        }

        $this->orderRepository->deleteWhere([
            [OrderDomainObjectAbstract::ID, 'in', $deletableOrderIds],
        ]);
    }

    public function createNewOrder(
        int $eventId,
        EventDomainObject $event,
        int $timeOutMinutes,
        string $locale,
        ?PromoCodeDomainObject $promoCode,
        ?AffiliateDomainObject $affiliate = null,
        ?string $sessionId = null,
    ): OrderDomainObject {
        $reservedUntil = Carbon::now()->addMinutes($timeOutMinutes);

        return $this->orderRepository->create([
            'event_id' => $eventId,
            'short_id' => IdHelper::shortId(IdHelper::ORDER_PREFIX),
            'reserved_until' => $reservedUntil->toString(),
            'status' => OrderStatus::RESERVED->name,
            'session_id' => $sessionId,
            'currency' => $event->getCurrency(),
            'public_id' => IdHelper::publicId(IdHelper::ORDER_PREFIX),
            'promo_code_id' => $promoCode?->getId(),
            'promo_code' => $promoCode?->getCode(),
            'affiliate_id' => $affiliate?->getId(),
            'locale' => $locale,
        ]);
    }

    /**
     * Update order totals by summing up all order items.
     * Platform fee and its tax are included at the item level.
     *
     * @param  Collection<OrderItemDomainObject>  $orderItems
     */
    public function updateOrderTotals(OrderDomainObject $order, Collection $orderItems): OrderDomainObject
    {
        $totalBeforeAdditions = 0;
        $totalTax = 0;
        $totalFee = 0;
        $totalGross = 0;

        foreach ($orderItems as $item) {
            $totalBeforeAdditions += $item->getTotalBeforeAdditions();
            $totalTax += $item->getTotalTax();
            $totalFee += $item->getTotalServiceFee();
            $totalGross += $item->getTotalGross();
        }

        $rollup = $this->taxAndFeeOrderRollupService->rollup($orderItems);

        $this->orderRepository->updateFromArray($order->getId(), [
            'total_before_additions' => $totalBeforeAdditions,
            'total_tax' => $totalTax,
            'total_fee' => $totalFee,
            'total_gross' => $totalGross,
            'taxes_and_fees_rollup' => $rollup,
        ]);

        return $this->orderRepository
            ->loadRelation(OrderItemDomainObject::class)
            ->findById($order->getId());
    }
}
