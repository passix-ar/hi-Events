<?php

// Added by Passix on 2026-06-11: deferred OrderFailed email for MercadoPago rejected payments.
namespace HiEvents\Jobs\Order;

use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Domain\Mail\SendOrderDetailsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Psr\Log\LoggerInterface;

class SendOrderFailedEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(private readonly int $orderId)
    {
    }

    public function handle(
        OrderRepositoryInterface $orderRepository,
        SendOrderDetailsService  $sendOrderDetailsService,
        LoggerInterface          $logger,
    ): void
    {
        $order = $orderRepository->findById($this->orderId);

        // A retried payment may have completed the order, or the organizer may have
        // cancelled it (in which case the buyer already received OrderCancelled).
        if (!$order->isOrderFailed() || $order->isOrderCancelled()) {
            $logger->info('Skipping OrderFailed email: order no longer in failed state', [
                'order_id' => $this->orderId,
            ]);
            return;
        }

        $sendOrderDetailsService->sendOrderFailedEmail($order);
    }
}
