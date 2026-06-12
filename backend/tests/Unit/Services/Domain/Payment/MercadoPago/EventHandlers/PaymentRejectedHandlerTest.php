<?php

namespace Tests\Unit\Services\Domain\Payment\MercadoPago\EventHandlers;

use Carbon\Carbon;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\Interfaces\DomainObjectInterface;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\Jobs\Order\SendOrderFailedEmailJob;
use HiEvents\Repository\Interfaces\MercadopagoPaymentRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Domain\Payment\MercadoPago\EventHandlers\PaymentRejectedHandler;
use Illuminate\Support\Facades\Bus;
use Mockery;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class PaymentRejectedHandlerTest extends TestCase
{
    private OrderRepositoryInterface|MockInterface $orderRepository;
    private MercadopagoPaymentRepositoryInterface|MockInterface $paymentRepository;
    private LoggerInterface|MockInterface $logger;
    private PaymentRejectedHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orderRepository = Mockery::mock(OrderRepositoryInterface::class);
        $this->paymentRepository = Mockery::mock(MercadopagoPaymentRepositoryInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);

        $this->handler = new PaymentRejectedHandler(
            $this->orderRepository,
            $this->paymentRepository,
            $this->logger,
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_awaiting_payment_order_is_marked_failed_and_email_job_dispatched(): void
    {
        Bus::fake();

        $reservedUntil = '2026-06-11 12:00:00';

        $order = Mockery::mock(OrderDomainObject::class);
        $order->shouldReceive('getId')->andReturn(55);
        $order->shouldReceive('getPaymentStatus')->andReturn(OrderPaymentStatus::AWAITING_PAYMENT->name);
        $order->shouldReceive('getReservedUntil')->andReturn($reservedUntil);

        $this->orderRepository->shouldReceive('findFirstWhere')
            ->with(['short_id' => 'ORDER-REF'])
            ->andReturn($order);

        $this->paymentRepository->shouldReceive('findFirstWhere')->andReturn(Mockery::mock(DomainObjectInterface::class));

        $this->orderRepository->shouldReceive('updateFromArray')
            ->once()
            ->with(55, [OrderDomainObjectAbstract::PAYMENT_STATUS => OrderPaymentStatus::PAYMENT_FAILED->name]);

        $this->logger->shouldReceive('info')->once();

        $this->handler->handle([
            'id'                 => 123,
            'status'             => 'rejected',
            'external_reference' => 'ORDER-REF',
        ]);

        Bus::assertDispatched(
            SendOrderFailedEmailJob::class,
            static fn (SendOrderFailedEmailJob $job) => $job->delay == Carbon::parse($reservedUntil)->addMinutes(5)
        );
    }

    public function test_email_job_delay_falls_back_when_order_has_no_reservation(): void
    {
        Bus::fake();
        Carbon::setTestNow('2026-06-11 10:00:00');

        $order = Mockery::mock(OrderDomainObject::class);
        $order->shouldReceive('getId')->andReturn(55);
        $order->shouldReceive('getPaymentStatus')->andReturn(OrderPaymentStatus::AWAITING_PAYMENT->name);
        $order->shouldReceive('getReservedUntil')->andReturn(null);

        $this->orderRepository->shouldReceive('findFirstWhere')->andReturn($order);
        $this->paymentRepository->shouldReceive('findFirstWhere')->andReturn(Mockery::mock(DomainObjectInterface::class));
        $this->orderRepository->shouldReceive('updateFromArray')->once();
        $this->logger->shouldReceive('info')->once();

        $this->handler->handle([
            'id'                 => 123,
            'status'             => 'rejected',
            'external_reference' => 'ORDER-REF',
        ]);

        Bus::assertDispatched(
            SendOrderFailedEmailJob::class,
            static fn (SendOrderFailedEmailJob $job) => $job->delay == Carbon::parse('2026-06-11 10:15:00')
        );
    }

    public function test_order_not_awaiting_payment_does_not_dispatch_email_job(): void
    {
        Bus::fake();

        $order = Mockery::mock(OrderDomainObject::class);
        $order->shouldReceive('getId')->andReturn(55);
        $order->shouldReceive('getPaymentStatus')->andReturn(OrderPaymentStatus::PAYMENT_RECEIVED->name);

        $this->orderRepository->shouldReceive('findFirstWhere')->andReturn($order);
        $this->paymentRepository->shouldReceive('findFirstWhere')->andReturn(Mockery::mock(DomainObjectInterface::class));
        $this->orderRepository->shouldNotReceive('updateFromArray');
        $this->logger->shouldReceive('info')->once();

        $this->handler->handle([
            'id'                 => 123,
            'status'             => 'rejected',
            'external_reference' => 'ORDER-REF',
        ]);

        Bus::assertNotDispatched(SendOrderFailedEmailJob::class);
    }

    public function test_order_not_found_logs_warning_and_dispatches_nothing(): void
    {
        Bus::fake();

        $this->orderRepository->shouldReceive('findFirstWhere')->andReturn(null);
        $this->logger->shouldReceive('warning')->once();

        $this->handler->handle([
            'id'                 => 123,
            'status'             => 'rejected',
            'external_reference' => 'MISSING-REF',
        ]);

        Bus::assertNotDispatched(SendOrderFailedEmailJob::class);
    }
}
