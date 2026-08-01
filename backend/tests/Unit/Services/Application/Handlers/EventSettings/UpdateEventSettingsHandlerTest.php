<?php

namespace Tests\Unit\Services\Application\Handlers\EventSettings;

use HiEvents\DomainObjects\Enums\CapacityChangeDirection;
use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\Events\CapacityChangedEvent;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Repository\Interfaces\AccountMercadopagoPlatformRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Services\Application\Handlers\EventSettings\DTO\UpdateEventSettingsDTO;
use HiEvents\Services\Application\Handlers\EventSettings\UpdateEventSettingsHandler;
use HiEvents\Services\Domain\Event\EventPaymentMethodsService;
use HiEvents\Services\Infrastructure\HtmlPurifier\HtmlPurifierService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Event;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

class UpdateEventSettingsHandlerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private EventSettingsRepositoryInterface $eventSettingsRepository;
    private EventRepositoryInterface $eventRepository;
    private AccountMercadopagoPlatformRepositoryInterface $platformRepository;
    private HtmlPurifierService $purifier;
    private DatabaseManager $databaseManager;
    private UpdateEventSettingsHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->eventSettingsRepository = Mockery::mock(EventSettingsRepositoryInterface::class);
        $this->eventRepository = Mockery::mock(EventRepositoryInterface::class);
        $this->platformRepository = Mockery::mock(AccountMercadopagoPlatformRepositoryInterface::class);
        $this->purifier = Mockery::mock(HtmlPurifierService::class);
        $this->databaseManager = Mockery::mock(DatabaseManager::class);

        $this->purifier->shouldReceive('purify')->andReturnUsing(fn($v) => $v);

        $this->databaseManager
            ->shouldReceive('transaction')
            ->andReturnUsing(fn($callback) => $callback());

        // Drafts skip the payment validation entirely, which keeps the pre-existing
        // tests focused on what they were written for.
        $this->givenEventStatus(EventStatus::DRAFT->name);

        $this->handler = new UpdateEventSettingsHandler(
            eventSettingsRepository: $this->eventSettingsRepository,
            eventRepository: $this->eventRepository,
            eventPaymentMethodsService: new EventPaymentMethodsService($this->platformRepository),
            purifier: $this->purifier,
            databaseManager: $this->databaseManager,
        );
    }

    private function givenEventStatus(string $status, int $accountId = 10): void
    {
        $event = new EventDomainObject();
        $event->setStatus($status);
        $event->setAccountId($accountId);

        $this->eventRepository->shouldReceive('findFirstWhere')->andReturn($event)->byDefault();
    }

    public function testDispatchesCapacityEventWhenAutoProcessToggledOn(): void
    {
        Event::fake();

        $existingSettings = new EventSettingDomainObject();
        $existingSettings->setWaitlistAutoProcess(false);

        $this->eventSettingsRepository
            ->shouldReceive('findFirstWhere')
            ->with(['event_id' => 1])
            ->twice()
            ->andReturn($existingSettings);

        $this->eventSettingsRepository
            ->shouldReceive('updateWhere')
            ->once();

        $dto = $this->createDTO(waitlist_auto_process: true);
        $this->handler->handle($dto);

        Event::assertDispatched(CapacityChangedEvent::class, function ($event) {
            return $event->eventId === 1
                && $event->productId === null
                && $event->direction === CapacityChangeDirection::INCREASED;
        });
    }

    public function testDoesNotDispatchEventWhenAutoProcessAlreadyEnabled(): void
    {
        Event::fake();

        $existingSettings = new EventSettingDomainObject();
        $existingSettings->setWaitlistAutoProcess(true);

        $this->eventSettingsRepository
            ->shouldReceive('findFirstWhere')
            ->with(['event_id' => 1])
            ->twice()
            ->andReturn($existingSettings);

        $this->eventSettingsRepository
            ->shouldReceive('updateWhere')
            ->once();

        $dto = $this->createDTO(waitlist_auto_process: true);
        $this->handler->handle($dto);

        Event::assertNotDispatched(CapacityChangedEvent::class);
    }

    public function testDoesNotDispatchEventWhenAutoProcessDisabled(): void
    {
        Event::fake();

        $existingSettings = new EventSettingDomainObject();
        $existingSettings->setWaitlistAutoProcess(true);

        $this->eventSettingsRepository
            ->shouldReceive('findFirstWhere')
            ->with(['event_id' => 1])
            ->twice()
            ->andReturn($existingSettings);

        $this->eventSettingsRepository
            ->shouldReceive('updateWhere')
            ->once();

        $dto = $this->createDTO(waitlist_auto_process: false);
        $this->handler->handle($dto);

        Event::assertNotDispatched(CapacityChangedEvent::class);
    }

    public function testLiveEventCannotBeLeftWithoutPaymentMethods(): void
    {
        $this->givenEventStatus(EventStatus::LIVE->name);

        $this->eventSettingsRepository->shouldReceive('findFirstWhere')->andReturn(new EventSettingDomainObject());
        $this->eventSettingsRepository->shouldNotReceive('updateWhere');

        $this->expectException(ResourceConflictException::class);

        $this->handler->handle($this->createDTO(paymentProviders: []));
    }

    public function testLiveEventCannotKeepMercadoPagoAloneWhenDisconnected(): void
    {
        $this->givenEventStatus(EventStatus::LIVE->name);
        $this->platformRepository->shouldReceive('isSetupCompleteForAccount')->andReturn(false);

        $this->eventSettingsRepository->shouldReceive('findFirstWhere')->andReturn(new EventSettingDomainObject());
        $this->eventSettingsRepository->shouldNotReceive('updateWhere');

        $this->expectException(ResourceConflictException::class);

        $this->handler->handle($this->createDTO(paymentProviders: [PaymentProviders::MERCADOPAGO->value]));
    }

    public function testLiveEventCanKeepOfflineOnly(): void
    {
        $this->givenEventStatus(EventStatus::LIVE->name);

        $this->eventSettingsRepository->shouldReceive('findFirstWhere')->andReturn(new EventSettingDomainObject());
        $this->eventSettingsRepository->shouldReceive('updateWhere')->once();

        $this->handler->handle($this->createDTO(paymentProviders: [PaymentProviders::OFFLINE->value]));
    }

    public function testDraftEventCanBeLeftWithoutPaymentMethods(): void
    {
        $this->givenEventStatus(EventStatus::DRAFT->name);

        $this->eventSettingsRepository->shouldReceive('findFirstWhere')->andReturn(new EventSettingDomainObject());
        $this->eventSettingsRepository->shouldReceive('updateWhere')->once();

        $this->handler->handle($this->createDTO(paymentProviders: []));
    }

    private function createDTO(?bool $waitlist_auto_process = null, array $paymentProviders = []): UpdateEventSettingsDTO
    {
        return UpdateEventSettingsDTO::fromArray([
            'account_id' => 1,
            'event_id' => 1,
            'payment_providers' => $paymentProviders,
            'post_checkout_message' => null,
            'pre_checkout_message' => null,
            'email_footer_message' => null,
            'continue_button_text' => 'Continue',
            'support_email' => 'test@test.com',
            'homepage_background_color' => '#ffffff',
            'homepage_primary_color' => '#000000',
            'homepage_primary_text_color' => '#000000',
            'homepage_secondary_color' => '#000000',
            'homepage_secondary_text_color' => '#ffffff',
            'homepage_body_background_color' => '#ffffff',
            'homepage_background_type' => 'COLOR',
            'require_attendee_details' => false,
            'attendee_details_collection_method' => 'PER_TICKET',
            'order_timeout_in_minutes' => 15,
            'website_url' => null,
            'maps_url' => null,
            'seo_title' => null,
            'seo_description' => null,
            'seo_keywords' => null,
            'waitlist_auto_process' => $waitlist_auto_process,
            'waitlist_offer_timeout_minutes' => 60,
        ]);
    }
}
