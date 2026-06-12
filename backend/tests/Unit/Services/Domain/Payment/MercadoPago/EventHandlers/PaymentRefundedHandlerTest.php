<?php

namespace Tests\Unit\Services\Domain\Payment\MercadoPago\EventHandlers;

use Closure;
use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\Interfaces\DomainObjectInterface;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\Status\OrderRefundStatus;
use HiEvents\Mail\Order\OrderRefunded;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\MercadopagoPaymentRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRefundRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Domain\EventStatistics\EventStatisticsRefundService;
use HiEvents\Services\Domain\Payment\MercadoPago\EventHandlers\PaymentRefundedHandler;
use HiEvents\Services\Infrastructure\DomainEvents\DomainEventDispatcherService;
use HiEvents\Services\Infrastructure\DomainEvents\Events\OrderEvent;
use HiEvents\Values\MoneyValue;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Database\DatabaseManager;
use Mockery;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class PaymentRefundedHandlerTest extends TestCase
{
    private OrderRepositoryInterface|MockInterface $orderRepository;
    private EventRepositoryInterface|MockInterface $eventRepository;
    private MercadopagoPaymentRepositoryInterface|MockInterface $paymentRepository;
    private OrderRefundRepositoryInterface|MockInterface $orderRefundRepository;
    private EventStatisticsRefundService|MockInterface $eventStatisticsRefundService;
    private DomainEventDispatcherService|MockInterface $domainEventDispatcherService;
    private Mailer|MockInterface $mailer;
    private DatabaseManager|MockInterface $databaseManager;
    private LoggerInterface|MockInterface $logger;
    private PaymentRefundedHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orderRepository = Mockery::mock(OrderRepositoryInterface::class);
        $this->eventRepository = Mockery::mock(EventRepositoryInterface::class);
        $this->paymentRepository = Mockery::mock(MercadopagoPaymentRepositoryInterface::class);
        $this->orderRefundRepository = Mockery::mock(OrderRefundRepositoryInterface::class);
        $this->eventStatisticsRefundService = Mockery::mock(EventStatisticsRefundService::class);
        $this->domainEventDispatcherService = Mockery::mock(DomainEventDispatcherService::class);
        $this->mailer = Mockery::mock(Mailer::class);
        $this->databaseManager = Mockery::mock(DatabaseManager::class);
        $this->logger = Mockery::mock(LoggerInterface::class);

        $this->databaseManager->shouldReceive('transaction')
            ->andReturnUsing(static fn (Closure $callback) => $callback());

        $this->handler = new PaymentRefundedHandler(
            $this->orderRepository,
            $this->eventRepository,
            $this->paymentRepository,
            $this->orderRefundRepository,
            $this->eventStatisticsRefundService,
            $this->domainEventDispatcherService,
            $this->mailer,
            $this->databaseManager,
            $this->logger,
        );
    }

    private function makeOrder(): OrderDomainObject|MockInterface
    {
        $order = Mockery::mock(OrderDomainObject::class);
        $order->shouldReceive('getId')->andReturn(77);
        $order->shouldReceive('getEventId')->andReturn(9);
        $order->shouldReceive('getCurrency')->andReturn('ARS');
        $order->shouldReceive('getTotalRefunded')->andReturn(0.0);
        $order->shouldReceive('getTotalGross')->andReturn(1500.0);
        $order->shouldReceive('getEmail')->andReturn('buyer@example.com');
        $order->shouldReceive('getLocale')->andReturn('es');

        return $order;
    }

    private function refundedPaymentData(string $status = 'refunded'): array
    {
        return [
            'id'                          => 987,
            'status'                      => $status,
            'status_detail'               => $status,
            'external_reference'          => 'ORDER-REF',
            'transaction_amount'          => 1500.0,
            'transaction_amount_refunded' => 1500.0,
            'currency_id'                 => 'ARS',
            'refunds'                     => [['id' => 555, 'amount' => 1500.0]],
        ];
    }

    public function test_full_refund_updates_order_and_notifies_buyer(): void
    {
        $order = $this->makeOrder();

        $this->paymentRepository->shouldReceive('updateWhere')->once();
        $this->orderRepository->shouldReceive('findFirstWhere')
            ->with(['short_id' => 'ORDER-REF'])
            ->andReturn($order);
        $this->orderRefundRepository->shouldReceive('findFirstWhere')
            ->with(['refund_id' => '555'])
            ->andReturn(null);

        $this->orderRepository->shouldReceive('increment')
            ->once()
            ->with(77, OrderDomainObjectAbstract::TOTAL_REFUNDED, 1500.0);

        $this->orderRepository->shouldReceive('updateFromArray')
            ->once()
            ->with(77, [OrderDomainObjectAbstract::REFUND_STATUS => OrderRefundStatus::REFUNDED->name]);

        $this->eventStatisticsRefundService->shouldReceive('updateForRefund')
            ->once()
            ->with($order, Mockery::type(MoneyValue::class));

        $this->orderRefundRepository->shouldReceive('create')
            ->once()
            ->with(Mockery::on(static function (array $attributes) {
                return $attributes['order_id'] === 77
                    && $attributes['payment_provider'] === PaymentProviders::MERCADOPAGO->value
                    && $attributes['refund_id'] === '555'
                    && $attributes['amount'] === 1500.0;
            }));

        $organizer = Mockery::mock(OrganizerDomainObject::class);
        $eventSettings = Mockery::mock(EventSettingDomainObject::class);
        $event = Mockery::mock(EventDomainObject::class);
        $event->shouldReceive('getOrganizer')->andReturn($organizer);
        $event->shouldReceive('getEventSettings')->andReturn($eventSettings);

        $this->eventRepository->shouldReceive('loadRelation')->twice()->andReturnSelf();
        $this->eventRepository->shouldReceive('findById')->with(9)->andReturn($event);

        $pendingMail = Mockery::mock();
        $this->mailer->shouldReceive('to')->once()->with('buyer@example.com')->andReturn($pendingMail);
        $pendingMail->shouldReceive('locale')->once()->with('es')->andReturnSelf();
        $pendingMail->shouldReceive('send')->once()->with(Mockery::type(OrderRefunded::class));

        $this->domainEventDispatcherService->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::type(OrderEvent::class));

        $this->logger->shouldReceive('info')->once();

        $this->handler->handle($this->refundedPaymentData());
    }

    public function test_duplicate_refund_is_skipped(): void
    {
        $order = $this->makeOrder();

        $this->paymentRepository->shouldReceive('updateWhere')->once();
        $this->orderRepository->shouldReceive('findFirstWhere')->andReturn($order);
        $this->orderRefundRepository->shouldReceive('findFirstWhere')
            ->with(['refund_id' => '555'])
            ->andReturn(Mockery::mock(DomainObjectInterface::class));

        $this->orderRepository->shouldNotReceive('increment');
        $this->orderRepository->shouldNotReceive('updateFromArray');
        $this->orderRefundRepository->shouldNotReceive('create');
        $this->mailer->shouldNotReceive('to');

        $this->logger->shouldReceive('info')->once();

        $this->handler->handle($this->refundedPaymentData());
    }

    public function test_chargeback_updates_order_without_notifying_buyer(): void
    {
        $order = $this->makeOrder();

        $this->paymentRepository->shouldReceive('updateWhere')->once();
        $this->orderRepository->shouldReceive('findFirstWhere')->andReturn($order);
        $this->orderRefundRepository->shouldReceive('findFirstWhere')->andReturn(null);

        $this->orderRepository->shouldReceive('increment')->once();
        $this->orderRepository->shouldReceive('updateFromArray')
            ->once()
            ->with(77, [OrderDomainObjectAbstract::REFUND_STATUS => OrderRefundStatus::REFUNDED->name]);
        $this->eventStatisticsRefundService->shouldReceive('updateForRefund')->once();
        $this->orderRefundRepository->shouldReceive('create')->once();
        $this->domainEventDispatcherService->shouldReceive('dispatch')->once();

        $this->mailer->shouldNotReceive('to');
        $this->eventRepository->shouldNotReceive('findById');

        $this->logger->shouldReceive('info')->once();

        $this->handler->handle($this->refundedPaymentData(status: 'charged_back'));
    }
}
