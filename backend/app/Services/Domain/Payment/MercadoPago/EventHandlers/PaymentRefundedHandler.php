<?php

// Added by Passix on 2026-05-25: MercadoPago Marketplace integration.
namespace HiEvents\Services\Domain\Payment\MercadoPago\EventHandlers;

use HiEvents\DomainObjects\Generated\MercadopagoPaymentDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Repository\Interfaces\MercadopagoPaymentRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;

class PaymentRefundedHandler
{
    public function __construct(
        private readonly OrderRepositoryInterface              $orderRepository,
        private readonly MercadopagoPaymentRepositoryInterface $paymentRepository,
        private readonly LoggerInterface                       $logger,
    ) {
    }

    public function handle(array $paymentData): void
    {
        $mpPaymentId = (string) $paymentData['id'];

        $this->paymentRepository->updateWhere(
            attributes: [
                MercadopagoPaymentDomainObjectAbstract::STATUS        => $paymentData['status'],
                MercadopagoPaymentDomainObjectAbstract::STATUS_DETAIL => $paymentData['status_detail'] ?? null,
            ],
            where: [MercadopagoPaymentDomainObjectAbstract::MP_PAYMENT_ID => $mpPaymentId],
        );

        $externalReference = $paymentData['external_reference'] ?? null;
        if ($externalReference) {
            $order = $this->orderRepository->findFirstWhere(['short_id' => $externalReference]);
            if ($order) {
                $this->orderRepository->updateFromArray($order->getId(), [
                    OrderDomainObjectAbstract::STATUS => OrderStatus::CANCELLED->name,
                ]);
            }
        }

        $this->logger->info('MercadoPago payment refunded handled', ['mp_payment_id' => $mpPaymentId]);
    }
}
