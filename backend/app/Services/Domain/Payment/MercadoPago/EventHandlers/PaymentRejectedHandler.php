<?php

// Added by Passix on 2026-05-25: MercadoPago Marketplace integration.
namespace HiEvents\Services\Domain\Payment\MercadoPago\EventHandlers;

use Carbon\Carbon;
use HiEvents\DomainObjects\Generated\MercadopagoPaymentDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\Jobs\Order\SendOrderFailedEmailJob;
use HiEvents\Repository\Interfaces\MercadopagoPaymentRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use Psr\Log\LoggerInterface;
use Throwable;

class PaymentRejectedHandler
{
    public function __construct(
        private readonly OrderRepositoryInterface              $orderRepository,
        private readonly MercadopagoPaymentRepositoryInterface $paymentRepository,
        private readonly LoggerInterface                       $logger,
    ) {
    }

    /**
     * @throws Throwable
     */
    public function handle(array $paymentData): void
    {
        $mpPaymentId = (string) $paymentData['id'];
        $externalReference = $paymentData['external_reference'] ?? null;

        if (!$externalReference) {
            return;
        }

        $order = $this->orderRepository->findFirstWhere(['short_id' => $externalReference]);

        if (!$order) {
            $this->logger->warning('Order not found for rejected MP payment', [
                'external_reference' => $externalReference,
                'mp_payment_id'      => $mpPaymentId,
            ]);
            return;
        }

        $existing = $this->paymentRepository->findFirstWhere([
            MercadopagoPaymentDomainObjectAbstract::MP_PAYMENT_ID => $mpPaymentId,
        ]);

        if (!$existing) {
            $this->paymentRepository->create([
                MercadopagoPaymentDomainObjectAbstract::ORDER_ID           => $order->getId(),
                MercadopagoPaymentDomainObjectAbstract::MP_PAYMENT_ID      => $mpPaymentId,
                MercadopagoPaymentDomainObjectAbstract::PREFERENCE_ID      => $paymentData['preference_id'] ?? null,
                MercadopagoPaymentDomainObjectAbstract::STATUS             => $paymentData['status'],
                MercadopagoPaymentDomainObjectAbstract::STATUS_DETAIL      => $paymentData['status_detail'] ?? null,
                MercadopagoPaymentDomainObjectAbstract::TRANSACTION_AMOUNT => $paymentData['transaction_amount'] ?? null,
                MercadopagoPaymentDomainObjectAbstract::CURRENCY_ID        => $paymentData['currency_id'] ?? null,
            ]);
        }

        if ($order->getPaymentStatus() === OrderPaymentStatus::AWAITING_PAYMENT->name) {
            $this->orderRepository->updateFromArray($order->getId(), [
                OrderDomainObjectAbstract::PAYMENT_STATUS => OrderPaymentStatus::PAYMENT_FAILED->name,
            ]);

            // Defer the OrderFailed email until the order reservation has definitely expired:
            // a rejected attempt can still be retried and approved within the same checkout,
            // and the job re-checks the order state before sending.
            $sendAt = $order->getReservedUntil()
                ? Carbon::parse($order->getReservedUntil())->addMinutes(5)
                : Carbon::now()->addMinutes(15);

            SendOrderFailedEmailJob::dispatch($order->getId())->delay($sendAt);
        }

        $this->logger->info('MercadoPago payment rejected handled', [
            'mp_payment_id' => $mpPaymentId,
            'order_id'      => $order->getId(),
            'status'        => $paymentData['status'],
        ]);
    }
}
