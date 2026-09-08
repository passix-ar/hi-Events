<?php

// Added by Passix on 2026-05-25: MercadoPago Marketplace integration.
namespace HiEvents\Services\Domain\Payment\MercadoPago\EventHandlers;

use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\DomainObjects\Generated\MercadopagoPaymentDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\MercadopagoPreferenceDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\OrderApplicationFeeStatus;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Events\OrderStatusChangedEvent;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\Generated\EventSettingDomainObjectAbstract;
use HiEvents\Exceptions\CannotAcceptPaymentException;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\MercadopagoPaymentRepositoryInterface;
use HiEvents\Repository\Interfaces\MercadopagoPreferenceRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Domain\Order\OrderApplicationFeeService;
use HiEvents\Services\Domain\Product\ProductQuantityUpdateService;
use HiEvents\Services\Domain\Seating\SeatOrderIntegrityService;
use HiEvents\Services\Infrastructure\DomainEvents\DomainEventDispatcherService;
use HiEvents\Services\Infrastructure\DomainEvents\Enums\DomainEventType;
use HiEvents\Services\Infrastructure\DomainEvents\Events\OrderEvent;
use Illuminate\Cache\Repository as Cache;
use Illuminate\Database\DatabaseManager;
use Psr\Log\LoggerInterface;
use Throwable;

class PaymentApprovedHandler
{
    public function __construct(
        private readonly OrderRepositoryInterface              $orderRepository,
        private readonly MercadopagoPreferenceRepositoryInterface $preferenceRepository,
        private readonly MercadopagoPaymentRepositoryInterface $paymentRepository,
        private readonly AttendeeRepositoryInterface           $attendeeRepository,
        private readonly ProductQuantityUpdateService          $quantityUpdateService,
        private readonly OrderApplicationFeeService            $orderApplicationFeeService,
        private readonly SeatOrderIntegrityService             $seatOrderIntegrityService,
        private readonly EventSettingsRepositoryInterface      $eventSettingsRepository,
        private readonly DomainEventDispatcherService          $domainEventDispatcherService,
        private readonly DatabaseManager                       $databaseManager,
        private readonly Cache                                 $cache,
        private readonly LoggerInterface                       $logger,
    ) {
    }

    /**
     * @throws Throwable
     */
    public function handle(array $paymentData): void
    {
        $mpPaymentId = (string) $paymentData['id'];

        if ($this->isAlreadyHandled($mpPaymentId)) {
            $this->logger->info('MercadoPago payment already handled', ['mp_payment_id' => $mpPaymentId]);
            return;
        }

        $fulfilledOrder = $this->databaseManager->transaction(function () use ($paymentData, $mpPaymentId) {
            $externalReference = $paymentData['external_reference'] ?? null;

            if (!$externalReference) {
                $this->logger->error('MercadoPago payment missing external_reference', $paymentData);
                return;
            }

            $order = $this->orderRepository
                ->loadRelation(OrderItemDomainObject::class)
                ->findFirstWhere(['short_id' => $externalReference]);

            if (!$order) {
                $this->logger->error('Order not found for MercadoPago payment', [
                    'external_reference' => $externalReference,
                    'mp_payment_id'      => $mpPaymentId,
                ]);
                return;
            }

            if (!in_array($order->getPaymentStatus(), [
                OrderPaymentStatus::AWAITING_PAYMENT->name,
                OrderPaymentStatus::PAYMENT_FAILED->name,
            ], true)) {
                $this->logger->info('Order not awaiting payment, skipping', ['order_id' => $order->getId()]);
                return;
            }

            if (!$this->paymentMatchesOrder($order, $paymentData)) {
                return;
            }

            $preference = $this->preferenceRepository->findFirstWhere([
                MercadopagoPreferenceDomainObjectAbstract::ORDER_ID => $order->getId(),
            ]);
            $paymentData['marketplace_fee'] = $preference?->getMarketplaceFee()
                ?? ($paymentData['marketplace_fee'] ?? 0);

            $this->storeMercadoPagoPayment($mpPaymentId, $order->getId(), $paymentData);

            $updatedOrder = $this->orderRepository
                ->loadRelation(OrderItemDomainObject::class)
                ->updateFromArray($order->getId(), [
                    OrderDomainObjectAbstract::PAYMENT_STATUS => OrderPaymentStatus::PAYMENT_RECEIVED->name,
                    OrderDomainObjectAbstract::STATUS         => OrderStatus::COMPLETED->name,
                    OrderDomainObjectAbstract::PAYMENT_PROVIDER => PaymentProviders::MERCADOPAGO->value,
                ]);

            $this->attendeeRepository->updateWhere(
                attributes: ['status' => AttendeeStatus::ACTIVE->name],
                where: [
                    'order_id' => $updatedOrder->getId(),
                    'status'   => AttendeeStatus::AWAITING_PAYMENT->name,
                ],
            );

            $this->quantityUpdateService->updateQuantitiesFromOrder($updatedOrder);

            /** @var EventSettingDomainObject $eventSettings */
            $eventSettings = $this->eventSettingsRepository->findFirstWhere([
                EventSettingDomainObjectAbstract::EVENT_ID => $updatedOrder->getEventId(),
            ]);

            event(new OrderStatusChangedEvent($updatedOrder, createInvoice: $eventSettings->getEnableInvoicing()));

            $this->domainEventDispatcherService->dispatch(
                new OrderEvent(type: DomainEventType::ORDER_CREATED, orderId: $updatedOrder->getId())
            );

            $this->storeApplicationFee($updatedOrder, $paymentData);
            $this->markAsHandled($mpPaymentId, $updatedOrder);

            return $updatedOrder;
        });

        if ($fulfilledOrder !== null) {
            $this->reportSeatsLostWhileAwaitingPayment($fulfilledOrder);
        }
    }

    /**
     * An expired reservation returns its seats to the inventory, so a payment approved long
     * after the fact can land on seats another buyer already took. Fulfilment is deliberately
     * not blocked here — refusing a payment is a decision that belongs with the refund path —
     * but it must not happen silently.
     *
     * This runs after the transaction has committed, and swallows its own failures: a
     * diagnostic must never be able to undo the payment it is observing.
     */
    private function reportSeatsLostWhileAwaitingPayment(OrderDomainObject $order): void
    {
        try {
            $lostSeats = $this->seatOrderIntegrityService->findSeatsLostByOrder($order->getId());

            if ($lostSeats === []) {
                return;
            }

            $this->logger->error('Seats were resold while the order was awaiting payment', [
                'order_id' => $order->getId(),
                'order_short_id' => $order->getShortId(),
                'event_id' => $order->getEventId(),
                'seat_labels' => $lostSeats,
            ]);
        } catch (Throwable $exception) {
            $this->logger->warning('Could not verify seat integrity for an approved payment', [
                'order_id' => $order->getId(),
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Reconcile the approved payment against the order before fulfilling it. The
     * preference is created charging the order's total_gross in its own currency, so
     * the amount paid must cover that total and the currency must match. A divergence
     * means the payment does not correspond to this order and must not grant tickets.
     */
    private function paymentMatchesOrder(OrderDomainObject $order, array $paymentData): bool
    {
        $paidAmount = (float) ($paymentData['transaction_amount'] ?? 0);
        $paidCurrency = strtoupper((string) ($paymentData['currency_id'] ?? ''));

        $expectedAmount = round((float) $order->getTotalGross(), 2);
        $expectedCurrency = strtoupper($order->getCurrency());

        if ($paidCurrency !== $expectedCurrency || $paidAmount + 0.01 < $expectedAmount) {
            $this->logger->error('MercadoPago payment does not match order, skipping fulfilment', [
                'order_id'          => $order->getId(),
                'paid_amount'       => $paidAmount,
                'expected_amount'   => $expectedAmount,
                'paid_currency'     => $paidCurrency,
                'expected_currency' => $expectedCurrency,
            ]);

            return false;
        }

        return true;
    }

    private function storeMercadoPagoPayment(string $mpPaymentId, int $orderId, array $data): void
    {
        $existing = $this->paymentRepository->findFirstWhere([
            MercadopagoPaymentDomainObjectAbstract::MP_PAYMENT_ID => $mpPaymentId,
        ]);

        if ($existing) {
            $this->paymentRepository->updateWhere(
                attributes: ['status' => $data['status'], 'status_detail' => $data['status_detail'] ?? null],
                where: ['mp_payment_id' => $mpPaymentId],
            );
            return;
        }

        $this->paymentRepository->create([
            MercadopagoPaymentDomainObjectAbstract::ORDER_ID           => $orderId,
            MercadopagoPaymentDomainObjectAbstract::MP_PAYMENT_ID      => $mpPaymentId,
            MercadopagoPaymentDomainObjectAbstract::PREFERENCE_ID      => $data['preference_id'] ?? null,
            MercadopagoPaymentDomainObjectAbstract::STATUS             => $data['status'],
            MercadopagoPaymentDomainObjectAbstract::STATUS_DETAIL      => $data['status_detail'] ?? null,
            MercadopagoPaymentDomainObjectAbstract::TRANSACTION_AMOUNT => $data['transaction_amount'] ?? null,
            MercadopagoPaymentDomainObjectAbstract::CURRENCY_ID        => $data['currency_id'] ?? null,
            MercadopagoPaymentDomainObjectAbstract::PAYMENT_TYPE_ID    => $data['payment_type_id'] ?? null,
            MercadopagoPaymentDomainObjectAbstract::PAYMENT_METHOD_ID  => $data['payment_method_id'] ?? null,
            MercadopagoPaymentDomainObjectAbstract::MARKETPLACE_FEE    => $data['marketplace_fee'] ?? null,
        ]);
    }

    private function storeApplicationFee(OrderDomainObject $order, array $paymentData): void
    {
        $fee = (float) ($paymentData['marketplace_fee'] ?? 0);
        $feeMinorUnit = (int) round($fee * 100);

        $this->orderApplicationFeeService->createOrderApplicationFee(
            orderId: $order->getId(),
            applicationFeeAmountMinorUnit: $feeMinorUnit,
            orderApplicationFeeStatus: OrderApplicationFeeStatus::PAID,
            paymentMethod: PaymentProviders::MERCADOPAGO,
            currency: strtolower($paymentData['currency_id'] ?? $order->getCurrency()),
        );
    }

    private function isAlreadyHandled(string $mpPaymentId): bool
    {
        return $this->cache->has('mp_payment_handled_' . $mpPaymentId);
    }

    private function markAsHandled(string $mpPaymentId, OrderDomainObject $order): void
    {
        $this->logger->info('MercadoPago payment approved and handled', [
            'mp_payment_id' => $mpPaymentId,
            'order_id'      => $order->getId(),
        ]);
        $this->cache->put('mp_payment_handled_' . $mpPaymentId, true, 3600);
    }
}
