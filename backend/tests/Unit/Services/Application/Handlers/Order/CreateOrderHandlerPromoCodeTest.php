<?php

namespace Tests\Unit\Services\Application\Handlers\Order;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\PromoCodeDomainObject;
use HiEvents\Repository\Interfaces\AffiliateRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Repository\Interfaces\PromoCodeRepositoryInterface;
use HiEvents\Services\Application\Handlers\Order\CreateOrderHandler;
use HiEvents\Services\Application\Handlers\Order\DTO\CreateOrderPublicDTO;
use HiEvents\Services\Domain\Order\OrderItemProcessingService;
use HiEvents\Services\Domain\Order\OrderManagementService;
use HiEvents\Services\Domain\Product\AvailableProductQuantitiesFetchService;
use HiEvents\Services\Domain\Product\DTO\AvailableProductQuantitiesResponseDTO;
use HiEvents\Services\Domain\PromoCode\PromoCodeUsageValidationService;
use HiEvents\Services\Domain\Seating\SeatClaimService;
use Illuminate\Database\DatabaseManager;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Tests\TestCase;

/**
 * Ported from upstream (v1.11.1-beta) and adapted to our handler, which
 * diverges for MercadoPago and seating. The third test is ours: it pins the
 * ordering that makes the feature safe for a buyer who abandoned and retried.
 */
class CreateOrderHandlerPromoCodeTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const EVENT_ID = 1;

    private const PROMO_CODE_ID = 5;

    public function test_promo_code_is_dropped_when_not_usable(): void
    {
        $captured = $this->runHandler(isUsable: false);

        $this->assertNull($captured['promoCode']);
    }

    public function test_promo_code_is_applied_when_usable(): void
    {
        $captured = $this->runHandler(isUsable: true);

        $this->assertInstanceOf(PromoCodeDomainObject::class, $captured['promoCode']);
        $this->assertSame(self::PROMO_CODE_ID, $captured['promoCode']->getId());
    }

    public function test_stale_reservations_of_the_session_are_deleted_before_the_usage_is_counted(): void
    {
        // A buyer who abandons the checkout and retries must not be blocked by
        // their own previous reservation, which would still count against the
        // code's limit if it were deleted after the check.
        $captured = $this->runHandler(isUsable: true);

        $this->assertSame(['deleteExistingOrders', 'isPromoCodeUsable'], $captured['calls']);
    }

    /**
     * @return array{promoCode: mixed, calls: string[]}
     */
    private function runHandler(bool $isUsable): array
    {
        $calls = [];

        $promoCode = (new PromoCodeDomainObject)
            ->setId(self::PROMO_CODE_ID)
            ->setCode('save50');

        $eventSettings = Mockery::mock(EventSettingDomainObject::class);
        $eventSettings->shouldReceive('getOrderTimeoutInMinutes')->andReturn(15);

        $event = Mockery::mock(EventDomainObject::class);
        $event->shouldReceive('getEventSettings')->andReturn($eventSettings);
        $event->shouldReceive('getCurrency')->andReturn('ARS');
        $event->shouldReceive('getStatus')->andReturn('LIVE');
        $event->shouldReceive('isEventInPast')->andReturn(false);

        $eventRepository = Mockery::mock(EventRepositoryInterface::class);
        $eventRepository->shouldReceive('loadRelation')->andReturnSelf();
        $eventRepository->shouldReceive('findById')->with(self::EVENT_ID)->andReturn($event);

        $promoCodeRepository = Mockery::mock(PromoCodeRepositoryInterface::class);
        $promoCodeRepository->shouldReceive('findFirstWhere')->andReturn($promoCode);

        $usageValidationService = Mockery::mock(PromoCodeUsageValidationService::class);
        $usageValidationService->shouldReceive('isPromoCodeUsable')
            ->with($promoCode)
            ->andReturnUsing(function () use (&$calls, $isUsable) {
                $calls[] = 'isPromoCodeUsable';

                return $isUsable;
            });

        $orderManagementService = Mockery::mock(OrderManagementService::class);
        $orderManagementService->shouldReceive('deleteExistingOrders')
            ->once()
            ->andReturnUsing(function () use (&$calls) {
                $calls[] = 'deleteExistingOrders';
            });

        $capturedPromoCode = false;
        $order = Mockery::mock(OrderDomainObject::class);
        $orderManagementService
            ->shouldReceive('createNewOrder')
            ->andReturnUsing(function ($eventId, $event, $timeOut, $locale, $promo) use (&$capturedPromoCode, $order) {
                $capturedPromoCode = $promo;

                return $order;
            });
        $orderManagementService->shouldReceive('updateOrderTotals')->andReturn($order);

        $orderItemProcessingService = Mockery::mock(OrderItemProcessingService::class);
        $orderItemProcessingService->shouldReceive('process')->andReturn(collect());

        $availabilityService = Mockery::mock(AvailableProductQuantitiesFetchService::class);
        $availabilityService->shouldReceive('getAvailableProductQuantities')
            ->andReturn(new AvailableProductQuantitiesResponseDTO(
                productQuantities: collect(),
                capacities: collect(),
            ));

        $seatClaimService = Mockery::mock(SeatClaimService::class);
        $seatClaimService->shouldReceive('claimSeatsForOrder');

        $databaseManager = Mockery::mock(DatabaseManager::class);
        $databaseManager->shouldReceive('statement')->andReturn(true);
        $databaseManager->shouldReceive('transaction')->andReturnUsing(fn ($callback) => $callback());

        $handler = new CreateOrderHandler(
            $eventRepository,
            $promoCodeRepository,
            Mockery::mock(AffiliateRepositoryInterface::class),
            Mockery::mock(ProductRepositoryInterface::class),
            $orderManagementService,
            $orderItemProcessingService,
            $availabilityService,
            $seatClaimService,
            $databaseManager,
            $usageValidationService,
        );

        $dto = new CreateOrderPublicDTO(
            products: collect(),
            is_user_authenticated: true,
            session_identifier: 'sess-123',
            order_locale: 'es',
            promo_code: 'save50',
            affiliate_code: null,
        );

        $handler->handle(self::EVENT_ID, $dto);

        return ['promoCode' => $capturedPromoCode, 'calls' => $calls];
    }
}
