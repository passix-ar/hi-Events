<?php

namespace Tests\Unit\Services\Domain\Event;

use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Repository\Interfaces\AccountMercadopagoPlatformRepositoryInterface;
use HiEvents\Services\Domain\Event\EventPaymentMethodsService;
use Mockery as m;
use Tests\TestCase;

class EventPaymentMethodsServiceTest extends TestCase
{
    private const ACCOUNT_ID = 10;

    // Resolved through __() so the assertions follow the catalog instead of pinning
    // the English source, which is what breaks once a message gets translated.
    private const CONFIGURE_A_METHOD = 'Please configure at least one payment method (MercadoPago or Offline).';
    private const CONNECT_MERCADOPAGO = 'MercadoPago is not connected. Please connect MercadoPago or enable offline payments.';

    private AccountMercadopagoPlatformRepositoryInterface $platformRepository;
    private EventPaymentMethodsService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->platformRepository = m::mock(AccountMercadopagoPlatformRepositoryInterface::class);
        $this->service = new EventPaymentMethodsService($this->platformRepository);
    }

    public function testNullProvidersAreNotUsable(): void
    {
        $this->assertSame([], $this->service->getUsableProviders(null, self::ACCOUNT_ID));
    }

    public function testEmptyProvidersAreNotUsable(): void
    {
        $this->assertSame([], $this->service->getUsableProviders([], self::ACCOUNT_ID));
    }

    public function testStripeIsNeverUsable(): void
    {
        $this->assertSame(
            [],
            $this->service->getUsableProviders([PaymentProviders::STRIPE->value], self::ACCOUNT_ID),
        );
    }

    public function testOfflineIsUsableWithoutMercadoPago(): void
    {
        $this->assertSame(
            [PaymentProviders::OFFLINE->value],
            $this->service->getUsableProviders([PaymentProviders::OFFLINE->value], self::ACCOUNT_ID),
        );
    }

    public function testMercadoPagoIsUsableWhenConnected(): void
    {
        $this->givenMercadoPagoIsConnected(true);

        $this->assertSame(
            [PaymentProviders::MERCADOPAGO->value],
            $this->service->getUsableProviders([PaymentProviders::MERCADOPAGO->value], self::ACCOUNT_ID),
        );
    }

    public function testMercadoPagoIsDroppedWhenDisconnected(): void
    {
        $this->givenMercadoPagoIsConnected(false);

        $this->assertSame(
            [],
            $this->service->getUsableProviders([PaymentProviders::MERCADOPAGO->value], self::ACCOUNT_ID),
        );
    }

    public function testDisconnectedMercadoPagoIsDroppedButOfflineSurvives(): void
    {
        $this->givenMercadoPagoIsConnected(false);

        $this->assertSame(
            [PaymentProviders::OFFLINE->value],
            $this->service->getUsableProviders(
                [PaymentProviders::MERCADOPAGO->value, PaymentProviders::OFFLINE->value],
                self::ACCOUNT_ID,
            ),
        );
    }

    public function testConnectionIsOnlyCheckedOncePerCall(): void
    {
        $this->platformRepository->shouldReceive('isSetupCompleteForAccount')
            ->with(self::ACCOUNT_ID)
            ->once()
            ->andReturn(true);

        $providers = [
            PaymentProviders::MERCADOPAGO->value,
            PaymentProviders::MERCADOPAGO->value,
            PaymentProviders::OFFLINE->value,
        ];

        $this->assertCount(3, $this->service->getUsableProviders($providers, self::ACCOUNT_ID));
    }

    public function testAssertPassesWhenAProviderIsUsable(): void
    {
        $this->expectNotToPerformAssertions();

        $this->service->assertHasUsableProvider([PaymentProviders::OFFLINE->value], self::ACCOUNT_ID);
    }

    public function testAssertFailsWithNoProviders(): void
    {
        $this->expectException(ResourceConflictException::class);
        $this->expectExceptionMessage(__(self::CONFIGURE_A_METHOD));

        $this->service->assertHasUsableProvider([], self::ACCOUNT_ID);
    }

    public function testAssertFailsWithStripeOnly(): void
    {
        $this->expectException(ResourceConflictException::class);
        $this->expectExceptionMessage(__(self::CONFIGURE_A_METHOD));

        $this->service->assertHasUsableProvider([PaymentProviders::STRIPE->value], self::ACCOUNT_ID);
    }

    public function testAssertTellsTheUserToConnectWhenMercadoPagoIsTheOnlyChoice(): void
    {
        $this->givenMercadoPagoIsConnected(false);

        $this->expectException(ResourceConflictException::class);
        $this->expectExceptionMessage(__(self::CONNECT_MERCADOPAGO));

        $this->service->assertHasUsableProvider([PaymentProviders::MERCADOPAGO->value], self::ACCOUNT_ID);
    }

    private function givenMercadoPagoIsConnected(bool $isConnected): void
    {
        $this->platformRepository->shouldReceive('isSetupCompleteForAccount')
            ->with(self::ACCOUNT_ID)
            ->andReturn($isConnected);
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }
}
