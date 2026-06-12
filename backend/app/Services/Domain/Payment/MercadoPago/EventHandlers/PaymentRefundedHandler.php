<?php

// Added by Passix on 2026-05-25: MercadoPago Marketplace integration.
// Updated by Passix on 2026-06-11: mirror Stripe's ChargeRefundUpdatedHandler (refund status,
// statistics, order_refunds record) and notify the buyer, since MP refunds are initiated
// externally from the MercadoPago dashboard and the webhook is the only notification point.
namespace HiEvents\Services\Domain\Payment\MercadoPago\EventHandlers;

use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\Generated\MercadopagoPaymentDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\Status\OrderRefundStatus;
use HiEvents\Mail\Order\OrderRefunded;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\MercadopagoPaymentRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRefundRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Domain\EventStatistics\EventStatisticsRefundService;
use HiEvents\Services\Infrastructure\DomainEvents\DomainEventDispatcherService;
use HiEvents\Services\Infrastructure\DomainEvents\Enums\DomainEventType;
use HiEvents\Services\Infrastructure\DomainEvents\Events\OrderEvent;
use HiEvents\Values\MoneyValue;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Database\DatabaseManager;
use Psr\Log\LoggerInterface;
use Throwable;

class PaymentRefundedHandler
{
    public function __construct(
        private readonly OrderRepositoryInterface              $orderRepository,
        private readonly EventRepositoryInterface              $eventRepository,
        private readonly MercadopagoPaymentRepositoryInterface $paymentRepository,
        private readonly OrderRefundRepositoryInterface        $orderRefundRepository,
        private readonly EventStatisticsRefundService          $eventStatisticsRefundService,
        private readonly DomainEventDispatcherService          $domainEventDispatcherService,
        private readonly Mailer                                $mailer,
        private readonly DatabaseManager                       $databaseManager,
        private readonly LoggerInterface                       $logger,
    ) {
    }

    /**
     * @throws Throwable
     */
    public function handle(array $paymentData): void
    {
        $this->databaseManager->transaction(function () use ($paymentData) {
            $mpPaymentId = (string) $paymentData['id'];

            $this->paymentRepository->updateWhere(
                attributes: [
                    MercadopagoPaymentDomainObjectAbstract::STATUS        => $paymentData['status'],
                    MercadopagoPaymentDomainObjectAbstract::STATUS_DETAIL => $paymentData['status_detail'] ?? null,
                ],
                where: [MercadopagoPaymentDomainObjectAbstract::MP_PAYMENT_ID => $mpPaymentId],
            );

            $externalReference = $paymentData['external_reference'] ?? null;
            if (!$externalReference) {
                $this->logger->warning('MercadoPago refund webhook missing external_reference', [
                    'mp_payment_id' => $mpPaymentId,
                ]);
                return;
            }

            $order = $this->orderRepository->findFirstWhere(['short_id' => $externalReference]);
            if (!$order) {
                $this->logger->warning('Order not found for refunded MP payment', [
                    'external_reference' => $externalReference,
                    'mp_payment_id'      => $mpPaymentId,
                ]);
                return;
            }

            $refundId = $this->resolveRefundId($paymentData, $mpPaymentId);

            $existingRefund = $this->orderRefundRepository->findFirstWhere([
                'refund_id' => $refundId,
            ]);

            if ($existingRefund) {
                $this->logger->info('MercadoPago refund already processed', [
                    'refund_id'     => $refundId,
                    'mp_payment_id' => $mpPaymentId,
                    'order_id'      => $order->getId(),
                ]);
                return;
            }

            $refundedAmount = (float) ($paymentData['transaction_amount_refunded']
                ?? $paymentData['transaction_amount']
                ?? 0);

            $this->updateOrderRefundedAmount($order->getId(), $refundedAmount);
            $this->updateOrderRefundStatus($order, $refundedAmount);
            $this->eventStatisticsRefundService->updateForRefund(
                $order,
                MoneyValue::fromFloat($refundedAmount, $order->getCurrency()),
            );
            $this->createOrderRefund($paymentData, $order, $refundId, $refundedAmount);

            if ($paymentData['status'] === 'refunded') {
                $this->notifyBuyer($order, MoneyValue::fromFloat($refundedAmount, $order->getCurrency()));
            }

            $this->domainEventDispatcherService->dispatch(
                new OrderEvent(
                    type: DomainEventType::ORDER_REFUNDED,
                    orderId: $order->getId(),
                ),
            );

            $this->logger->info('MercadoPago payment refunded handled', [
                'mp_payment_id'   => $mpPaymentId,
                'order_id'        => $order->getId(),
                'status'          => $paymentData['status'],
                'refunded_amount' => $refundedAmount,
            ]);
        });
    }

    private function resolveRefundId(array $paymentData, string $mpPaymentId): string
    {
        $refunds = $paymentData['refunds'] ?? [];
        $lastRefund = is_array($refunds) && $refunds !== [] ? end($refunds) : null;

        return (string) ($lastRefund['id'] ?? ('mp-payment-' . $mpPaymentId));
    }

    private function updateOrderRefundedAmount(int $orderId, float $refundedAmount): void
    {
        $this->orderRepository->increment(
            id: $orderId,
            column: OrderDomainObjectAbstract::TOTAL_REFUNDED,
            amount: $refundedAmount,
        );
    }

    private function updateOrderRefundStatus(OrderDomainObject $order, float $refundedAmount): void
    {
        $status = $refundedAmount + $order->getTotalRefunded() >= $order->getTotalGross()
            ? OrderRefundStatus::REFUNDED->name
            : OrderRefundStatus::PARTIALLY_REFUNDED->name;

        $this->orderRepository->updateFromArray($order->getId(), [
            OrderDomainObjectAbstract::REFUND_STATUS => $status,
        ]);
    }

    private function createOrderRefund(array $paymentData, OrderDomainObject $order, string $refundId, float $refundedAmount): void
    {
        $this->orderRefundRepository->create([
            'order_id'         => $order->getId(),
            'payment_provider' => PaymentProviders::MERCADOPAGO->value,
            'refund_id'        => $refundId,
            'amount'           => $refundedAmount,
            'currency'         => $order->getCurrency(),
            'status'           => $paymentData['status'],
            'metadata'         => [
                'mp_payment_id' => (string) $paymentData['id'],
                'status_detail' => $paymentData['status_detail'] ?? null,
            ],
        ]);
    }

    private function notifyBuyer(OrderDomainObject $order, MoneyValue $refundAmount): void
    {
        $event = $this->eventRepository
            ->loadRelation(new Relationship(OrganizerDomainObject::class, name: 'organizer'))
            ->loadRelation(EventSettingDomainObject::class)
            ->findById($order->getEventId());

        $this->mailer
            ->to($order->getEmail())
            ->locale($order->getLocale())
            ->send(new OrderRefunded(
                order: $order,
                event: $event,
                organizer: $event->getOrganizer(),
                eventSettings: $event->getEventSettings(),
                refundAmount: $refundAmount,
            ));
    }
}
