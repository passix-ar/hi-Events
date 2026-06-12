<?php

namespace Tests\Unit\Jobs\Order;

use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\Jobs\Order\SendOrderFailedEmailJob;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Domain\Mail\SendOrderDetailsService;
use Mockery;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class SendOrderFailedEmailJobTest extends TestCase
{
    private OrderRepositoryInterface|MockInterface $orderRepository;
    private SendOrderDetailsService|MockInterface $sendOrderDetailsService;
    private LoggerInterface|MockInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orderRepository = Mockery::mock(OrderRepositoryInterface::class);
        $this->sendOrderDetailsService = Mockery::mock(SendOrderDetailsService::class);
        $this->logger = Mockery::mock(LoggerInterface::class);
    }

    public function test_email_is_sent_when_order_is_still_failed(): void
    {
        $order = Mockery::mock(OrderDomainObject::class);
        $order->shouldReceive('isOrderFailed')->andReturn(true);
        $order->shouldReceive('isOrderCancelled')->andReturn(false);

        $this->orderRepository->shouldReceive('findById')->with(42)->andReturn($order);
        $this->sendOrderDetailsService->shouldReceive('sendOrderFailedEmail')->once()->with($order);

        (new SendOrderFailedEmailJob(42))->handle(
            $this->orderRepository,
            $this->sendOrderDetailsService,
            $this->logger,
        );
    }

    public function test_email_is_skipped_when_order_completed_via_retry(): void
    {
        $order = Mockery::mock(OrderDomainObject::class);
        $order->shouldReceive('isOrderFailed')->andReturn(false);

        $this->orderRepository->shouldReceive('findById')->with(42)->andReturn($order);
        $this->sendOrderDetailsService->shouldNotReceive('sendOrderFailedEmail');
        $this->logger->shouldReceive('info')->once();

        (new SendOrderFailedEmailJob(42))->handle(
            $this->orderRepository,
            $this->sendOrderDetailsService,
            $this->logger,
        );
    }

    public function test_email_is_skipped_when_order_was_cancelled(): void
    {
        $order = Mockery::mock(OrderDomainObject::class);
        $order->shouldReceive('isOrderFailed')->andReturn(true);
        $order->shouldReceive('isOrderCancelled')->andReturn(true);

        $this->orderRepository->shouldReceive('findById')->with(42)->andReturn($order);
        $this->sendOrderDetailsService->shouldNotReceive('sendOrderFailedEmail');
        $this->logger->shouldReceive('info')->once();

        (new SendOrderFailedEmailJob(42))->handle(
            $this->orderRepository,
            $this->sendOrderDetailsService,
            $this->logger,
        );
    }
}
