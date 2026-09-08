<?php

declare(strict_types=1);

namespace Tests\Feature\Repository\Eloquent;

use HiEvents\DomainObjects\Enums\SeatState;
use HiEvents\DomainObjects\SeatDomainObject;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\DomainObjects\Status\SeatingSectionStatus;
use HiEvents\Helper\IdHelper;
use HiEvents\Models\Attendee;
use HiEvents\Models\Event;
use HiEvents\Models\Order;
use HiEvents\Models\Organizer;
use HiEvents\Models\Product;
use HiEvents\Models\ProductCategory;
use HiEvents\Models\ProductPrice;
use HiEvents\Models\Seat;
use HiEvents\Models\SeatingSection;
use HiEvents\Models\User;
use HiEvents\Repository\Interfaces\SeatRepositoryInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The seat lifecycle lives in raw SQL, so it is covered against the real database.
 * A seat held by an order that never got paid must return to the inventory once the
 * reservation expires; one backed by a live or completed order must not. The map and
 * claimSeats have to agree on that, which is why every case asserts both.
 */
class SeatRepositoryTest extends TestCase
{
    use DatabaseTransactions;

    private SeatRepositoryInterface $repository;

    private Event $event;

    private SeatingSection $section;

    private Product $product;

    private ProductPrice $productPrice;

    private int $seatNumber = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = $this->app->make(SeatRepositoryInterface::class);

        // Event's `creating` hook resolves user_id from the authenticated user.
        $user = User::factory()->password(Str::random(16))->withAccount()->create();
        $this->actingAs($user);
        $account = $user->accounts()->first();

        $organizer = Organizer::create([
            'account_id' => $account->id,
            'name' => 'Passix Test Org',
            'email' => 'org@test.passix',
            'currency' => 'ARS',
            'timezone' => 'America/Argentina/Buenos_Aires',
        ]);

        $this->event = Event::create([
            'title' => 'Teatro con butacas',
            'account_id' => $account->id,
            'organizer_id' => $organizer->id,
            'status' => 'LIVE',
            'start_date' => now()->addMonth(),
            'end_date' => now()->addMonth()->addHours(3),
            'currency' => 'ARS',
            'timezone' => 'America/Argentina/Buenos_Aires',
            'short_id' => Str::random(8),
        ]);

        $category = ProductCategory::create([
            'name' => 'Entradas',
            'event_id' => $this->event->id,
            'order' => 0,
            'is_hidden' => false,
        ]);

        $this->product = Product::create([
            'title' => 'Platea',
            'event_id' => $this->event->id,
            'product_category_id' => $category->id,
            'type' => 'PAID',
            'product_type' => 'TICKET',
            'order' => 0,
            'is_hidden' => false,
        ]);

        $this->productPrice = ProductPrice::create([
            'product_id' => $this->product->id,
            'price' => 10000.00,
            'initial_quantity_available' => 100,
            'quantity_available' => 100,
            'quantity_sold' => 0,
            'is_hidden' => false,
            'order' => 0,
        ]);

        $this->section = SeatingSection::create([
            'event_id' => $this->event->id,
            'product_id' => $this->product->id,
            'name' => 'Platea baja',
            'row_count' => 1,
            'seats_per_row' => 1,
            'status' => SeatingSectionStatus::ACTIVE->name,
        ]);
    }

    public function test_seats_of_an_expired_reserved_order_return_to_the_inventory(): void
    {
        $abandoned = $this->makeOrder(OrderStatus::RESERVED, now()->subMinute());
        $seat = $this->makeSeat();
        $this->assignSeatTo($seat, $abandoned);

        $buyer = $this->makeOrder(OrderStatus::RESERVED, now()->addMinutes(15));

        $this->assertSame(1, $this->claim($seat, $buyer));

        $seat->refresh();
        $this->assertSame($buyer->id, (int) $seat->order_id);
        $this->assertNull($seat->attendee_id);
    }

    public function test_seats_of_an_expired_reserved_order_are_available_on_the_map(): void
    {
        $abandoned = $this->makeOrder(OrderStatus::RESERVED, now()->subMinute());
        $seat = $this->makeSeat();
        $this->assignSeatTo($seat, $abandoned);

        $this->assertSame(SeatState::AVAILABLE->name, $this->stateOf($seat));
        $this->assertSame(1, $this->sectionCountFor(SeatState::AVAILABLE));
    }

    public function test_seats_held_by_a_live_reservation_are_protected(): void
    {
        $inCheckout = $this->makeOrder(OrderStatus::RESERVED, now()->addMinutes(15));
        $seat = $this->makeSeat();
        $this->assignSeatTo($seat, $inCheckout);

        $otherBuyer = $this->makeOrder(OrderStatus::RESERVED, now()->addMinutes(15));

        $this->assertSame(0, $this->claim($seat, $otherBuyer));
        $this->assertSame(SeatState::HELD->name, $this->stateOf($seat));

        $seat->refresh();
        $this->assertSame($inCheckout->id, (int) $seat->order_id);
        $this->assertNotNull($seat->attendee_id);
    }

    public function test_seats_of_a_completed_order_are_protected(): void
    {
        $paid = $this->makeOrder(OrderStatus::COMPLETED, now()->subDay());
        $seat = $this->makeSeat();
        $this->assignSeatTo($seat, $paid);

        $otherBuyer = $this->makeOrder(OrderStatus::RESERVED, now()->addMinutes(15));

        $this->assertSame(0, $this->claim($seat, $otherBuyer));
        $this->assertSame(SeatState::SOLD->name, $this->stateOf($seat));

        $seat->refresh();
        $this->assertSame($paid->id, (int) $seat->order_id);
        $this->assertNotNull($seat->attendee_id);
    }

    public function test_seats_of_a_cancelled_order_return_to_the_inventory(): void
    {
        $cancelled = $this->makeOrder(OrderStatus::CANCELLED, now()->addMinutes(15));
        $seat = $this->makeSeat();
        $this->assignSeatTo($seat, $cancelled);

        $buyer = $this->makeOrder(OrderStatus::RESERVED, now()->addMinutes(15));

        $this->assertSame(1, $this->claim($seat, $buyer));

        $seat->refresh();
        $this->assertSame($buyer->id, (int) $seat->order_id);
        $this->assertNull($seat->attendee_id);
    }

    public function test_disabled_seats_are_never_claimed(): void
    {
        $seat = $this->makeSeat(isDisabled: true);
        $buyer = $this->makeOrder(OrderStatus::RESERVED, now()->addMinutes(15));

        $this->assertSame(0, $this->claim($seat, $buyer));
        $this->assertSame(SeatState::DISABLED->name, $this->stateOf($seat));
    }

    public function test_a_seat_whose_attendee_outlives_its_order_is_not_offered_nor_claimed(): void
    {
        $order = $this->makeOrder(OrderStatus::RESERVED, now()->subMinute());
        $seat = $this->makeSeat();
        $this->assignSeatTo($seat, $order);
        $seat->update(['order_id' => null]);

        $buyer = $this->makeOrder(OrderStatus::RESERVED, now()->addMinutes(15));

        $this->assertSame(0, $this->claim($seat, $buyer));
        $this->assertSame(SeatState::SOLD->name, $this->stateOf($seat));
    }

    private function claim(Seat $seat, Order $order): int
    {
        return $this->repository->claimSeats(
            orderId: $order->id,
            eventId: $this->event->id,
            seatIds: [$seat->id],
            sectionIds: [$this->section->id],
        );
    }

    private function stateOf(Seat $seat): ?string
    {
        return $this->repository
            ->findByEventIdWithState($this->event->id)
            ->first(static fn (SeatDomainObject $found) => $found->getId() === $seat->id)
            ?->getState();
    }

    private function sectionCountFor(SeatState $state): int
    {
        return $this->repository->getSeatCountsBySection($this->event->id)[$this->section->id][$state->name] ?? 0;
    }

    private function makeOrder(OrderStatus $status, Carbon $reservedUntil): Order
    {
        return Order::create([
            'event_id' => $this->event->id,
            'short_id' => IdHelper::shortId(IdHelper::ORDER_PREFIX),
            'public_id' => Str::random(20),
            'session_id' => Str::random(40),
            'status' => $status->name,
            'payment_status' => OrderPaymentStatus::AWAITING_PAYMENT->name,
            'currency' => 'ARS',
            'total_before_additions' => 10000.00,
            'total_gross' => 10000.00,
            'total_tax' => 0,
            'total_fee' => 0,
            'first_name' => 'Ana',
            'last_name' => 'Compradora',
            'email' => 'ana@test.passix',
            'reserved_until' => $reservedUntil,
        ]);
    }

    private function makeSeat(bool $isDisabled = false): Seat
    {
        $this->seatNumber++;

        return Seat::create([
            'event_id' => $this->event->id,
            'seating_section_id' => $this->section->id,
            'row_label' => 'A',
            'seat_number' => $this->seatNumber,
            'label' => 'A'.$this->seatNumber,
            'is_disabled' => $isDisabled,
        ]);
    }

    /**
     * Reproduces what CompleteOrderHandler leaves behind: the seat carries both the
     * order and the attendee before a single peso has been collected.
     */
    private function assignSeatTo(Seat $seat, Order $order): void
    {
        $attendee = Attendee::create([
            'order_id' => $order->id,
            'event_id' => $this->event->id,
            'product_id' => $this->product->id,
            'product_price_id' => $this->productPrice->id,
            'status' => 'AWAITING_PAYMENT',
            'first_name' => 'Ana',
            'last_name' => 'Compradora',
            'email' => 'ana@test.passix',
            'public_id' => Str::random(20),
            'short_id' => IdHelper::shortId(IdHelper::ATTENDEE_PREFIX),
        ]);

        $seat->update([
            'order_id' => $order->id,
            'attendee_id' => $attendee->id,
        ]);
    }
}
