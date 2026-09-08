<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Domain\Payment\MercadoPago;

use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\OrderApplicationFeeStatus;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Events\OrderStatusChangedEvent;
use HiEvents\Helper\IdHelper;
use HiEvents\Models\Attendee;
use HiEvents\Models\Event;
use HiEvents\Models\EventSetting;
use HiEvents\Models\MercadopagoPayment;
use HiEvents\Models\MercadopagoPreference;
use HiEvents\Models\Order;
use HiEvents\Models\OrderApplicationFee;
use HiEvents\Models\OrderItem;
use HiEvents\Models\Organizer;
use HiEvents\Models\Product;
use HiEvents\Models\ProductCategory;
use HiEvents\Models\ProductPrice;
use HiEvents\Models\User;
use HiEvents\Services\Domain\Payment\MercadoPago\EventHandlers\PaymentApprovedHandler;
use HiEvents\Services\Infrastructure\DomainEvents\DomainEventDispatcherService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Support\Str;
use Mockery as m;
use Tests\TestCase;

/**
 * End-to-end coverage of the money path: an approved MercadoPago payment
 * arriving via webhook must complete the order, activate the attendee,
 * count the sale, and record payment + application fee — against the real
 * database, so mass-assignment or schema regressions surface here instead
 * of in production.
 */
class MercadoPagoPaymentApprovedFlowTest extends TestCase
{
    use DatabaseTransactions;

    private const ORDER_TOTAL = 35000.00;

    private const MARKETPLACE_FEE = 1750.00;

    private const MP_PAYMENT_ID = '111222333';

    private Order $order;

    private Attendee $attendee;

    private ProductPrice $productPrice;

    protected function setUp(): void
    {
        parent::setUp();

        EventFacade::fake([OrderStatusChangedEvent::class]);

        // DomainEventDispatcherService type-hints the concrete Dispatcher, which
        // Event::fake() replaces — bind a stub so the handler still resolves.
        $this->app->instance(
            DomainEventDispatcherService::class,
            m::mock(DomainEventDispatcherService::class)->shouldIgnoreMissing(),
        );

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

        $event = Event::create([
            'title' => 'Evento de prueba',
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
            'event_id' => $event->id,
            'order_timeout_in_minutes' => 15,
            'attendee_details_collection_method' => 'PER_TICKET',
        ]);

        $category = ProductCategory::create([
            'name' => 'Entradas',
            'event_id' => $event->id,
            'order' => 0,
            'is_hidden' => false,
        ]);

        $product = Product::create([
            'title' => 'Entrada General',
            'event_id' => $event->id,
            'product_category_id' => $category->id,
            'type' => 'PAID',
            'product_type' => 'TICKET',
            'order' => 0,
            'is_hidden' => false,
        ]);

        $this->productPrice = ProductPrice::create([
            'product_id' => $product->id,
            'price' => self::ORDER_TOTAL,
            'initial_quantity_available' => 100,
            'quantity_available' => 100,
            'quantity_sold' => 0,
            'is_hidden' => false,
            'order' => 0,
        ]);

        $this->order = Order::create([
            'event_id' => $event->id,
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
            'reserved_until' => now()->addMinutes(15),
        ]);

        OrderItem::create([
            'order_id' => $this->order->id,
            'product_id' => $product->id,
            'product_price_id' => $this->productPrice->id,
            'item_name' => 'Entrada General',
            'quantity' => 1,
            'price' => self::ORDER_TOTAL,
            'total_before_additions' => self::ORDER_TOTAL,
            'total_gross' => self::ORDER_TOTAL,
        ]);

        $this->attendee = Attendee::create([
            'order_id' => $this->order->id,
            'event_id' => $event->id,
            'product_id' => $product->id,
            'product_price_id' => $this->productPrice->id,
            'status' => AttendeeStatus::AWAITING_PAYMENT->name,
            'first_name' => 'Ana',
            'last_name' => 'Compradora',
            'email' => 'ana@test.passix',
            'public_id' => Str::random(20),
            'short_id' => IdHelper::shortId(IdHelper::ATTENDEE_PREFIX),
        ]);

        MercadopagoPreference::create([
            'order_id' => $this->order->id,
            'preference_id' => 'pref-123',
            'status' => 'created',
            'marketplace_fee' => self::MARKETPLACE_FEE,
        ]);
    }

    public function test_approved_payment_completes_order_activates_ticket_and_records_money(): void
    {
        $this->handlePayment();

        $this->order->refresh();
        $this->assertSame(OrderStatus::COMPLETED->name, $this->order->status);
        $this->assertSame(OrderPaymentStatus::PAYMENT_RECEIVED->name, $this->order->payment_status);
        $this->assertSame(PaymentProviders::MERCADOPAGO->value, $this->order->payment_provider);

        $this->attendee->refresh();
        $this->assertSame(AttendeeStatus::ACTIVE->name, $this->attendee->status);

        $this->productPrice->refresh();
        $this->assertSame(1, (int) $this->productPrice->quantity_sold);

        $payment = MercadopagoPayment::where('mp_payment_id', self::MP_PAYMENT_ID)->firstOrFail();
        $this->assertSame($this->order->id, (int) $payment->order_id);
        $this->assertSame('approved', $payment->status);
        $this->assertSame('accredited', $payment->status_detail);
        $this->assertSame('pref-123', $payment->preference_id);
        $this->assertSame('ARS', $payment->currency_id);
        $this->assertSame('credit_card', $payment->payment_type_id);
        $this->assertSame('visa', $payment->payment_method_id);
        $this->assertEqualsWithDelta(self::ORDER_TOTAL, (float) $payment->transaction_amount, 0.001);
        $this->assertEqualsWithDelta(self::MARKETPLACE_FEE, (float) $payment->marketplace_fee, 0.001);

        $fee = OrderApplicationFee::where('order_id', $this->order->id)->firstOrFail();
        $this->assertEqualsWithDelta(self::MARKETPLACE_FEE, (float) $fee->amount, 0.001);
        $this->assertSame('ars', $fee->currency);
        $this->assertSame(OrderApplicationFeeStatus::PAID->value, $fee->status);
        $this->assertSame(PaymentProviders::MERCADOPAGO->value, $fee->payment_method);
        $this->assertNotNull($fee->paid_at);

        EventFacade::assertDispatchedTimes(OrderStatusChangedEvent::class, 1);
    }

    public function test_duplicate_webhook_delivery_has_no_second_effect(): void
    {
        $this->handlePayment();
        $this->handlePayment();

        $this->assertPaymentProcessedExactlyOnce();
    }

    public function test_replay_after_dedup_cache_expiry_is_stopped_by_order_status(): void
    {
        $this->handlePayment();

        // The first dedup layer is a 1h cache entry; wipe it to simulate
        // MercadoPago re-sending the webhook much later. The durable guard is
        // the order no longer being AWAITING_PAYMENT.
        Cache::flush();

        $this->handlePayment();

        $this->assertPaymentProcessedExactlyOnce();
    }

    public function test_payment_with_wrong_amount_does_not_fulfil_the_order(): void
    {
        $this->handlePayment(['transaction_amount' => 100.00]);

        $this->order->refresh();
        $this->assertSame(OrderStatus::RESERVED->name, $this->order->status);
        $this->assertSame(OrderPaymentStatus::AWAITING_PAYMENT->name, $this->order->payment_status);

        $this->attendee->refresh();
        $this->assertSame(AttendeeStatus::AWAITING_PAYMENT->name, $this->attendee->status);

        $this->assertSame(0, MercadopagoPayment::where('mp_payment_id', self::MP_PAYMENT_ID)->count());
        $this->assertSame(0, OrderApplicationFee::where('order_id', $this->order->id)->count());

        EventFacade::assertNotDispatched(OrderStatusChangedEvent::class);
    }

    public function test_application_fee_falls_back_to_payload_when_preference_has_no_fee(): void
    {
        MercadopagoPreference::where('order_id', $this->order->id)->update(['marketplace_fee' => null]);

        $this->handlePayment(['marketplace_fee' => 999.00]);

        $fee = OrderApplicationFee::where('order_id', $this->order->id)->firstOrFail();
        $this->assertEqualsWithDelta(999.00, (float) $fee->amount, 0.001);
    }

    private function handlePayment(array $overrides = []): void
    {
        app(PaymentApprovedHandler::class)->handle(array_merge([
            'id' => self::MP_PAYMENT_ID,
            'external_reference' => $this->order->short_id,
            'status' => 'approved',
            'status_detail' => 'accredited',
            'transaction_amount' => self::ORDER_TOTAL,
            'currency_id' => 'ARS',
            'payment_type_id' => 'credit_card',
            'payment_method_id' => 'visa',
            'preference_id' => 'pref-123',
        ], $overrides));
    }

    private function assertPaymentProcessedExactlyOnce(): void
    {
        $this->assertSame(1, MercadopagoPayment::where('mp_payment_id', self::MP_PAYMENT_ID)->count());
        $this->assertSame(1, OrderApplicationFee::where('order_id', $this->order->id)->count());

        $this->productPrice->refresh();
        $this->assertSame(1, (int) $this->productPrice->quantity_sold);

        $this->attendee->refresh();
        $this->assertSame(AttendeeStatus::ACTIVE->name, $this->attendee->status);

        EventFacade::assertDispatchedTimes(OrderStatusChangedEvent::class, 1);
    }
}
