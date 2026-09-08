<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Domain\Payment\MercadoPago;

use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\DomainObjects\Status\SeatingSectionStatus;
use HiEvents\Events\OrderStatusChangedEvent;
use HiEvents\Helper\IdHelper;
use HiEvents\Models\Attendee;
use HiEvents\Models\Event;
use HiEvents\Models\EventSetting;
use HiEvents\Models\MercadopagoPreference;
use HiEvents\Models\Order;
use HiEvents\Models\OrderItem;
use HiEvents\Models\Organizer;
use HiEvents\Models\Product;
use HiEvents\Models\ProductCategory;
use HiEvents\Models\ProductPrice;
use HiEvents\Models\Seat;
use HiEvents\Models\SeatingSection;
use HiEvents\Models\User;
use HiEvents\Services\Domain\Payment\MercadoPago\EventHandlers\PaymentApprovedHandler;
use HiEvents\Services\Infrastructure\DomainEvents\DomainEventDispatcherService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Support\Str;
use Mockery as m;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * A reservation that expires now returns its seats to the inventory, so a MercadoPago payment
 * approved late can land on a seat somebody else already bought. Fulfilment must go through
 * either way — refusing a payment we cannot refund would be worse — but the conflict has to be
 * reported instead of passing silently. Both halves are asserted against the real database.
 */
class PaymentApprovedSeatIntegrityTest extends TestCase
{
    use DatabaseTransactions;

    private const ORDER_TOTAL = 10000.00;

    private Order $order;

    private Attendee $attendee;

    private Seat $seat;

    private Event $event;

    private LoggerInterface|MockInterface $logger;

    protected function setUp(): void
    {
        parent::setUp();

        EventFacade::fake([OrderStatusChangedEvent::class]);

        $this->app->instance(
            DomainEventDispatcherService::class,
            m::mock(DomainEventDispatcherService::class)->shouldIgnoreMissing(),
        );

        $this->logger = m::spy(LoggerInterface::class);
        $this->app->instance(LoggerInterface::class, $this->logger);

        $user = User::factory()->password(Str::random(16))->withAccount()->create();
        $this->actingAs($user);
        $account = $user->accounts()->first();

        $organizer = Organizer::create([
            'account_id' => $account->id,
            'name' => 'Teatro Passix',
            'email' => 'teatro@test.passix',
            'currency' => 'ARS',
            'timezone' => 'America/Argentina/Buenos_Aires',
        ]);

        $this->event = Event::create([
            'title' => 'Funcion con butacas',
            'account_id' => $account->id,
            'organizer_id' => $organizer->id,
            'status' => 'LIVE',
            'start_date' => now()->addMonth(),
            'end_date' => now()->addMonth()->addHours(3),
            'currency' => 'ARS',
            'timezone' => 'America/Argentina/Buenos_Aires',
            'short_id' => Str::random(8),
        ]);

        EventSetting::create([
            'event_id' => $this->event->id,
            'order_timeout_in_minutes' => 15,
            'attendee_details_collection_method' => 'ORDER',
        ]);

        $category = ProductCategory::create([
            'name' => 'Entradas',
            'event_id' => $this->event->id,
            'order' => 0,
            'is_hidden' => false,
        ]);

        $product = Product::create([
            'title' => 'Platea',
            'event_id' => $this->event->id,
            'product_category_id' => $category->id,
            'type' => 'PAID',
            'product_type' => 'TICKET',
            'order' => 0,
            'is_hidden' => false,
        ]);

        $price = ProductPrice::create([
            'product_id' => $product->id,
            'price' => self::ORDER_TOTAL,
            'initial_quantity_available' => 100,
            'quantity_available' => 100,
            'quantity_sold' => 0,
            'is_hidden' => false,
            'order' => 0,
        ]);

        $section = SeatingSection::create([
            'event_id' => $this->event->id,
            'product_id' => $product->id,
            'name' => 'Platea baja',
            'row_count' => 1,
            'seats_per_row' => 1,
            'status' => SeatingSectionStatus::ACTIVE->name,
        ]);

        $this->order = Order::create([
            'event_id' => $this->event->id,
            'short_id' => IdHelper::shortId(IdHelper::ORDER_PREFIX),
            'public_id' => Str::random(20),
            'session_id' => Str::random(40),
            'status' => OrderStatus::RESERVED->name,
            'payment_status' => OrderPaymentStatus::AWAITING_PAYMENT->name,
            'currency' => 'ARS',
            'total_before_additions' => self::ORDER_TOTAL,
            'total_gross' => self::ORDER_TOTAL,
            'total_tax' => 0,
            'total_fee' => 0,
            'first_name' => 'Ana',
            'last_name' => 'Compradora',
            'email' => 'ana@test.passix',
            'reserved_until' => now()->subMinute(),
        ]);

        OrderItem::create([
            'order_id' => $this->order->id,
            'product_id' => $product->id,
            'product_price_id' => $price->id,
            'item_name' => 'Platea',
            'quantity' => 1,
            'price' => self::ORDER_TOTAL,
            'total_before_additions' => self::ORDER_TOTAL,
            'total_gross' => self::ORDER_TOTAL,
        ]);

        $this->attendee = Attendee::create([
            'order_id' => $this->order->id,
            'event_id' => $this->event->id,
            'product_id' => $product->id,
            'product_price_id' => $price->id,
            'status' => AttendeeStatus::AWAITING_PAYMENT->name,
            'first_name' => 'Ana',
            'last_name' => 'Compradora',
            'email' => 'ana@test.passix',
            'public_id' => Str::random(20),
            'short_id' => IdHelper::shortId(IdHelper::ATTENDEE_PREFIX),
            'seat_label' => 'Platea baja - A1',
        ]);

        $this->seat = Seat::create([
            'event_id' => $this->event->id,
            'seating_section_id' => $section->id,
            'row_label' => 'A',
            'seat_number' => 1,
            'label' => 'A1',
            'order_id' => $this->order->id,
            'attendee_id' => $this->attendee->id,
        ]);

        MercadopagoPreference::create([
            'order_id' => $this->order->id,
            'preference_id' => 'pref-seat-integrity',
            'status' => 'created',
            'marketplace_fee' => 0,
        ]);
    }

    public function test_a_payment_whose_seat_is_intact_completes_without_noise(): void
    {
        $this->handlePayment('seat-ok-'.Str::random(8));

        $this->order->refresh();
        $this->assertSame(OrderStatus::COMPLETED->name, $this->order->status);

        $this->logger->shouldNotHaveReceived('error');
    }

    public function test_a_payment_arriving_after_the_seat_was_resold_still_completes(): void
    {
        $this->resellTheSeat();

        $this->handlePayment('seat-lost-'.Str::random(8));

        $this->order->refresh();
        $this->assertSame(OrderStatus::COMPLETED->name, $this->order->status);
        $this->assertSame(OrderPaymentStatus::PAYMENT_RECEIVED->name, $this->order->payment_status);

        $this->attendee->refresh();
        $this->assertSame(AttendeeStatus::ACTIVE->name, $this->attendee->status);
    }

    public function test_the_resold_seat_is_reported_by_label(): void
    {
        $this->resellTheSeat();

        $this->handlePayment('seat-report-'.Str::random(8));

        $this->logger->shouldHaveReceived('error')
            ->withArgs(function (string $message, array $context): bool {
                return str_contains($message, 'resold')
                    && $context['order_id'] === $this->order->id
                    && $context['seat_labels'] === ['Platea baja - A1'];
            })
            ->once();
    }

    /**
     * What claimSeats does when a second buyer takes the seat of an expired reservation.
     */
    private function resellTheSeat(): void
    {
        $newOwner = Order::create([
            'event_id' => $this->event->id,
            'short_id' => IdHelper::shortId(IdHelper::ORDER_PREFIX),
            'public_id' => Str::random(20),
            'session_id' => Str::random(40),
            'status' => OrderStatus::RESERVED->name,
            'payment_status' => OrderPaymentStatus::AWAITING_PAYMENT->name,
            'currency' => 'ARS',
            'total_before_additions' => self::ORDER_TOTAL,
            'total_gross' => self::ORDER_TOTAL,
            'total_tax' => 0,
            'total_fee' => 0,
            'first_name' => 'Beto',
            'last_name' => 'Comprador',
            'email' => 'beto@test.passix',
            'reserved_until' => now()->addMinutes(15),
        ]);

        $this->seat->update([
            'order_id' => $newOwner->id,
            'attendee_id' => null,
        ]);
    }

    private function handlePayment(string $mpPaymentId): void
    {
        app(PaymentApprovedHandler::class)->handle([
            'id' => $mpPaymentId,
            'external_reference' => $this->order->short_id,
            'status' => 'approved',
            'status_detail' => 'accredited',
            'transaction_amount' => self::ORDER_TOTAL,
            'currency_id' => 'ARS',
            'payment_type_id' => 'credit_card',
            'payment_method_id' => 'visa',
            'preference_id' => 'pref-seat-integrity',
        ]);
    }
}
