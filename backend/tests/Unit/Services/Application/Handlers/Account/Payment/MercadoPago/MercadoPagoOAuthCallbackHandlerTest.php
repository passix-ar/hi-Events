<?php

namespace Tests\Unit\Services\Application\Handlers\Account\Payment\MercadoPago;

use HiEvents\DomainObjects\AccountMercadopagoPlatformDomainObject;
use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\Repository\Interfaces\AccountMercadopagoPlatformRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Services\Application\Handlers\Account\Payment\MercadoPago\MercadoPagoOAuthCallbackHandler;
use HiEvents\Services\Domain\Payment\MercadoPago\MercadoPagoOAuthService;
use Illuminate\Support\Collection;
use Mockery as m;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class MercadoPagoOAuthCallbackHandlerTest extends TestCase
{
    private const ACCOUNT_ID = 10;
    private const EVENT_ID = 1;

    private MercadoPagoOAuthService $oauthService;
    private AccountMercadopagoPlatformRepositoryInterface $platformRepository;
    private EventRepositoryInterface $eventRepository;
    private EventSettingsRepositoryInterface $eventSettingsRepository;
    private MercadoPagoOAuthCallbackHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->oauthService = m::mock(MercadoPagoOAuthService::class);
        $this->platformRepository = m::mock(AccountMercadopagoPlatformRepositoryInterface::class);
        $this->eventRepository = m::mock(EventRepositoryInterface::class);
        $this->eventSettingsRepository = m::mock(EventSettingsRepositoryInterface::class);
        $logger = m::mock(LoggerInterface::class);

        $logger->shouldReceive('info')->byDefault();
        $logger->shouldReceive('warning')->byDefault();

        $this->oauthService->shouldReceive('decodeState')
            ->with('state')
            ->andReturn(self::ACCOUNT_ID);

        $this->oauthService->shouldReceive('exchangeCodeForToken')
            ->with('code')
            ->andReturn([
                'user_id' => '999',
                'access_token' => 'access',
                'refresh_token' => 'refresh',
                'public_key' => 'public',
                'expires_in' => 3600,
            ]);

        $this->handler = new MercadoPagoOAuthCallbackHandler(
            $this->oauthService,
            $this->platformRepository,
            $this->eventRepository,
            $this->eventSettingsRepository,
            $logger,
        );
    }

    public function testFirstConnectionEnablesMercadoPagoOnExistingEvents(): void
    {
        $this->givenNoSellerConflict();
        $this->givenExistingPlatform(null);

        $this->platformRepository->shouldReceive('create')->once();

        $this->givenAccountEvents([self::EVENT_ID]);
        $this->givenEventSettings([PaymentProviders::OFFLINE->value]);

        $this->eventSettingsRepository->shouldReceive('updateWhere')
            ->once()
            ->with(
                m::on(fn($attrs) => $attrs['payment_providers'] === [
                    PaymentProviders::OFFLINE->value,
                    PaymentProviders::MERCADOPAGO->value,
                ]),
                m::on(fn($where) => $where['event_id'] === self::EVENT_ID),
            )
            ->andReturn(1);

        $this->handler->handle('code', 'state');

        $this->assertTrue(true);
    }

    public function testFirstConnectionSkipsEventsThatAlreadyHaveMercadoPago(): void
    {
        $this->givenNoSellerConflict();
        $this->givenExistingPlatform(null);

        $this->platformRepository->shouldReceive('create')->once();

        $this->givenAccountEvents([self::EVENT_ID]);
        $this->givenEventSettings([PaymentProviders::MERCADOPAGO->value]);

        $this->eventSettingsRepository->shouldNotReceive('updateWhere');

        $this->handler->handle('code', 'state');

        $this->assertTrue(true);
    }

    public function testReconnectionDoesNotTouchEventSettings(): void
    {
        $existing = m::mock(AccountMercadopagoPlatformDomainObject::class);
        $existing->shouldReceive('getId')->andReturn(5);

        $this->givenNoSellerConflict();
        $this->givenExistingPlatform($existing);

        $this->platformRepository->shouldReceive('includeDeleted')
            ->andReturnSelf();
        $this->platformRepository->shouldReceive('updateFromArray')
            ->once()
            ->with(5, m::type('array'));

        $this->eventRepository->shouldNotReceive('findWhere');
        $this->eventSettingsRepository->shouldNotReceive('updateWhere');

        $this->handler->handle('code', 'state');

        $this->assertTrue(true);
    }

    private function givenNoSellerConflict(): void
    {
        $this->platformRepository->shouldReceive('includeDeleted')
            ->andReturnSelf();

        $this->platformRepository->shouldReceive('findFirstWhere')
            ->with(m::on(fn($where) => array_key_exists('mp_user_id', $where)), m::type('array'))
            ->andReturn(null);
    }

    private function givenExistingPlatform(?AccountMercadopagoPlatformDomainObject $platform): void
    {
        $this->platformRepository->shouldReceive('findFirstWhere')
            ->with(m::on(fn($where) => array_key_exists('account_id', $where)), m::type('array'))
            ->andReturn($platform);
    }

    private function givenAccountEvents(array $eventIds): void
    {
        $events = collect($eventIds)->map(function (int $eventId) {
            $event = m::mock(EventDomainObject::class);
            $event->shouldReceive('getId')->andReturn($eventId);

            return $event;
        });

        $this->eventRepository->shouldReceive('findWhere')
            ->with(['account_id' => self::ACCOUNT_ID])
            ->andReturn(new Collection($events->all()));
    }

    private function givenEventSettings(array $providers): void
    {
        $settings = m::mock(EventSettingDomainObject::class);
        $settings->shouldReceive('getPaymentProviders')->andReturn($providers);

        $this->eventSettingsRepository->shouldReceive('findFirstWhere')
            ->with(['event_id' => self::EVENT_ID])
            ->andReturn($settings);
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }
}
