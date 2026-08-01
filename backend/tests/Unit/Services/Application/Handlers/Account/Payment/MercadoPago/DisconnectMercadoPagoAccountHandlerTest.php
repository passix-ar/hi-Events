<?php

namespace Tests\Unit\Services\Application\Handlers\Account\Payment\MercadoPago;

use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\Exceptions\MercadoPago\CannotDisconnectMercadoPagoException;
use HiEvents\Repository\Interfaces\AccountMercadopagoPlatformRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Application\Handlers\Account\Payment\MercadoPago\DisconnectMercadoPagoAccountHandler;
use HiEvents\Services\Domain\Payment\MercadoPago\MercadoPagoDisconnectImpactService;
use Illuminate\Support\Collection;
use Mockery as m;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class DisconnectMercadoPagoAccountHandlerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const ACCOUNT_ID = 10;

    private AccountMercadopagoPlatformRepositoryInterface $platformRepository;
    private EventRepositoryInterface $eventRepository;
    private DisconnectMercadoPagoAccountHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformRepository = m::mock(AccountMercadopagoPlatformRepositoryInterface::class);
        $this->eventRepository = m::mock(EventRepositoryInterface::class);

        $this->handler = new DisconnectMercadoPagoAccountHandler(
            $this->platformRepository,
            new MercadoPagoDisconnectImpactService($this->eventRepository),
        );
    }

    public function testDisconnectsWhenThereAreNoLiveEvents(): void
    {
        $this->givenLiveEvents([]);

        $this->platformRepository->shouldReceive('forceDeleteByAccountId')
            ->with(self::ACCOUNT_ID)
            ->once();

        $this->handler->handle(self::ACCOUNT_ID);
    }

    public function testDisconnectsWhenLiveEventsAlsoAcceptOffline(): void
    {
        $this->givenLiveEvents([
            $this->event('Fiesta', [PaymentProviders::MERCADOPAGO->value, PaymentProviders::OFFLINE->value]),
        ]);

        $this->platformRepository->shouldReceive('forceDeleteByAccountId')
            ->with(self::ACCOUNT_ID)
            ->once();

        $this->handler->handle(self::ACCOUNT_ID);
    }

    public function testDisconnectsWhenLiveEventsDoNotUseMercadoPago(): void
    {
        $this->givenLiveEvents([
            $this->event('Solo offline', [PaymentProviders::OFFLINE->value]),
        ]);

        $this->platformRepository->shouldReceive('forceDeleteByAccountId')
            ->with(self::ACCOUNT_ID)
            ->once();

        $this->handler->handle(self::ACCOUNT_ID);
    }

    public function testBlocksWhenALiveEventOnlyAcceptsMercadoPago(): void
    {
        $this->givenLiveEvents([
            $this->event('Recital', [PaymentProviders::MERCADOPAGO->value]),
        ]);

        $this->platformRepository->shouldNotReceive('forceDeleteByAccountId');

        $this->expectException(CannotDisconnectMercadoPagoException::class);
        $this->expectExceptionMessage('Recital');

        $this->handler->handle(self::ACCOUNT_ID);
    }

    public function testBlockingMessageNamesEveryAffectedEvent(): void
    {
        $this->givenLiveEvents([
            $this->event('Recital', [PaymentProviders::MERCADOPAGO->value]),
            $this->event('Feria', [PaymentProviders::MERCADOPAGO->value]),
            $this->event('Taller', [PaymentProviders::OFFLINE->value]),
        ]);

        try {
            $this->handler->handle(self::ACCOUNT_ID);
            $this->fail('Expected the disconnect to be blocked.');
        } catch (CannotDisconnectMercadoPagoException $e) {
            $this->assertStringContainsString('Recital', $e->getMessage());
            $this->assertStringContainsString('Feria', $e->getMessage());
            $this->assertStringNotContainsString('Taller', $e->getMessage());
        }
    }

    private function givenLiveEvents(array $events): void
    {
        $this->eventRepository->shouldReceive('loadRelation')->andReturnSelf();
        $this->eventRepository->shouldReceive('findWhere')->andReturn(new Collection($events));
    }

    private function event(string $title, array $paymentProviders): EventDomainObject
    {
        $settings = new EventSettingDomainObject();
        $settings->setPaymentProviders($paymentProviders);

        $event = new EventDomainObject();
        $event->setTitle($title);
        $event->setEventSettings($settings);

        return $event;
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }
}
