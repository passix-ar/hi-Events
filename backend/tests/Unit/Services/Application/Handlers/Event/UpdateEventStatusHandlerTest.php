<?php

namespace Tests\Unit\Services\Application\Handlers\Event;

use HiEvents\DomainObjects\AccountDomainObject;
use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\Exceptions\AccountNotVerifiedException;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Repository\Interfaces\AccountMercadopagoPlatformRepositoryInterface;
use HiEvents\Repository\Interfaces\AccountRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Services\Application\Handlers\Event\DTO\UpdateEventStatusDTO;
use HiEvents\Services\Application\Handlers\Event\UpdateEventStatusHandler;
use HiEvents\Services\Domain\Event\EventPaymentMethodsService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Queue;
use Mockery as m;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class UpdateEventStatusHandlerTest extends TestCase
{
    private const EVENT_ID = 1;
    private const ACCOUNT_ID = 10;

    private EventRepositoryInterface $eventRepository;
    private AccountRepositoryInterface $accountRepository;
    private EventSettingsRepositoryInterface $eventSettingsRepository;
    private AccountMercadopagoPlatformRepositoryInterface $platformRepository;
    private UpdateEventStatusHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->eventRepository = m::mock(EventRepositoryInterface::class);
        $this->accountRepository = m::mock(AccountRepositoryInterface::class);
        $this->eventSettingsRepository = m::mock(EventSettingsRepositoryInterface::class);
        $this->platformRepository = m::mock(AccountMercadopagoPlatformRepositoryInterface::class);
        $logger = m::mock(LoggerInterface::class);
        $databaseManager = m::mock(DatabaseManager::class);

        $databaseManager->shouldReceive('transaction')
            ->andReturnUsing(fn($callback) => $callback());

        $logger->shouldReceive('info')->byDefault();

        $this->handler = new UpdateEventStatusHandler(
            $this->eventRepository,
            $this->accountRepository,
            $this->eventSettingsRepository,
            new EventPaymentMethodsService($this->platformRepository),
            $logger,
            $databaseManager,
        );
    }

    public function testPublishFailsWithoutPaymentProviders(): void
    {
        $this->givenVerifiedAccount();
        $this->givenEventExists();
        $this->givenEventPaymentProviders([]);

        $this->expectException(ResourceConflictException::class);

        $this->handler->handle($this->liveDto());
    }

    public function testPublishFailsWhenSettingsAreMissing(): void
    {
        $this->givenVerifiedAccount();
        $this->givenEventExists();

        $this->eventSettingsRepository->shouldReceive('findFirstWhere')
            ->with(['event_id' => self::EVENT_ID])
            ->andReturn(null);

        $this->expectException(ResourceConflictException::class);

        $this->handler->handle($this->liveDto());
    }

    public function testPublishFailsWithOnlyStripeProvider(): void
    {
        $this->givenVerifiedAccount();
        $this->givenEventExists();
        $this->givenEventPaymentProviders([PaymentProviders::STRIPE->value]);

        $this->expectException(ResourceConflictException::class);

        $this->handler->handle($this->liveDto());
    }

    public function testPublishFailsWithMercadoPagoOnlyAndAccountNotConnected(): void
    {
        $this->givenVerifiedAccount();
        $this->givenEventExists();
        $this->givenEventPaymentProviders([PaymentProviders::MERCADOPAGO->value]);

        $this->platformRepository->shouldReceive('isSetupCompleteForAccount')
            ->with(self::ACCOUNT_ID)
            ->andReturn(false);

        $this->expectException(ResourceConflictException::class);

        $this->handler->handle($this->liveDto());
    }

    public function testPublishSucceedsWithMercadoPagoOnlyAndAccountConnected(): void
    {
        $this->givenVerifiedAccount();
        $event = $this->givenEventExists();
        $this->givenEventPaymentProviders([PaymentProviders::MERCADOPAGO->value]);

        $this->platformRepository->shouldReceive('isSetupCompleteForAccount')
            ->with(self::ACCOUNT_ID)
            ->andReturn(true);

        $this->expectStatusUpdate(EventStatus::LIVE->name);

        $result = $this->handler->handle($this->liveDto());

        $this->assertSame($event, $result);
    }

    public function testPublishSucceedsWithOfflineProvider(): void
    {
        $this->givenVerifiedAccount();
        $event = $this->givenEventExists();
        $this->givenEventPaymentProviders([PaymentProviders::OFFLINE->value]);

        $this->expectStatusUpdate(EventStatus::LIVE->name);

        $result = $this->handler->handle($this->liveDto());

        $this->assertSame($event, $result);
    }

    public function testPublishSucceedsWithMercadoPagoAndOfflineWhenMercadoPagoIsDisconnected(): void
    {
        $this->givenVerifiedAccount();
        $event = $this->givenEventExists();
        $this->givenEventPaymentProviders([
            PaymentProviders::MERCADOPAGO->value,
            PaymentProviders::OFFLINE->value,
        ]);
        $this->platformRepository->shouldReceive('isSetupCompleteForAccount')
            ->with(self::ACCOUNT_ID)
            ->andReturn(false);

        $this->expectStatusUpdate(EventStatus::LIVE->name);

        $result = $this->handler->handle($this->liveDto());

        $this->assertSame($event, $result);
    }

    public function testUnpublishSkipsPaymentValidation(): void
    {
        $this->givenVerifiedAccount();
        $event = $this->givenEventExists();

        $this->eventSettingsRepository->shouldNotReceive('findFirstWhere');
        $this->platformRepository->shouldNotReceive('isSetupCompleteForAccount');

        $this->expectStatusUpdate(EventStatus::DRAFT->name);

        $result = $this->handler->handle(new UpdateEventStatusDTO(
            status: EventStatus::DRAFT->name,
            eventId: self::EVENT_ID,
            accountId: self::ACCOUNT_ID,
        ));

        $this->assertSame($event, $result);
    }

    public function testArchiveSkipsPaymentValidation(): void
    {
        $this->givenVerifiedAccount();
        $event = $this->givenEventExists();

        $this->eventSettingsRepository->shouldNotReceive('findFirstWhere');

        $this->expectStatusUpdate(EventStatus::ARCHIVED->name);

        $result = $this->handler->handle(new UpdateEventStatusDTO(
            status: EventStatus::ARCHIVED->name,
            eventId: self::EVENT_ID,
            accountId: self::ACCOUNT_ID,
        ));

        $this->assertSame($event, $result);
    }

    public function testUnverifiedAccountCannotUpdateStatus(): void
    {
        $account = m::mock(AccountDomainObject::class);
        $account->shouldReceive('getAccountVerifiedAt')->andReturn(null);

        $this->accountRepository->shouldReceive('findById')
            ->with(self::ACCOUNT_ID)
            ->andReturn($account);

        $this->expectException(AccountNotVerifiedException::class);

        $this->handler->handle($this->liveDto());
    }

    public function testEventNotBelongingToAccountIsRejected(): void
    {
        $this->givenVerifiedAccount();

        $this->eventRepository->shouldReceive('findFirstWhere')
            ->with(['id' => self::EVENT_ID, 'account_id' => self::ACCOUNT_ID])
            ->andReturn(null);

        $this->eventSettingsRepository->shouldNotReceive('findFirstWhere');
        $this->eventRepository->shouldNotReceive('updateWhere');

        $this->expectException(ResourceNotFoundException::class);

        $this->handler->handle($this->liveDto());
    }

    private function liveDto(): UpdateEventStatusDTO
    {
        return new UpdateEventStatusDTO(
            status: EventStatus::LIVE->name,
            eventId: self::EVENT_ID,
            accountId: self::ACCOUNT_ID,
        );
    }

    private function givenVerifiedAccount(): void
    {
        $account = m::mock(AccountDomainObject::class);
        $account->shouldReceive('getAccountVerifiedAt')->andReturn('2024-01-01');

        $this->accountRepository->shouldReceive('findById')
            ->with(self::ACCOUNT_ID)
            ->andReturn($account);
    }

    private function givenEventExists(): EventDomainObject
    {
        $event = m::mock(EventDomainObject::class);
        $event->shouldReceive('getId')->andReturn(self::EVENT_ID);

        $this->eventRepository->shouldReceive('findFirstWhere')
            ->with(['id' => self::EVENT_ID, 'account_id' => self::ACCOUNT_ID])
            ->andReturn($event);

        return $event;
    }

    private function givenEventPaymentProviders(array $providers): void
    {
        $settings = m::mock(EventSettingDomainObject::class);
        $settings->shouldReceive('getPaymentProviders')->andReturn($providers);

        $this->eventSettingsRepository->shouldReceive('findFirstWhere')
            ->with(['event_id' => self::EVENT_ID])
            ->andReturn($settings);
    }

    private function expectStatusUpdate(string $status): void
    {
        $this->eventRepository->shouldReceive('updateWhere')
            ->once()
            ->with(
                m::on(fn($attrs) => $attrs['status'] === $status),
                m::on(fn($where) => $where['id'] === self::EVENT_ID && $where['account_id'] === self::ACCOUNT_ID),
            )
            ->andReturn(1);
    }

    protected function tearDown(): void
    {
        m::close();
        parent::tearDown();
    }
}
