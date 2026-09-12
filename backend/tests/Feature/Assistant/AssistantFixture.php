<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Helper\IdHelper;
use HiEvents\Models\Account;
use HiEvents\Models\AccountConfiguration;
use HiEvents\Models\Event;
use HiEvents\Models\EventStatistic;
use HiEvents\Models\Order;
use HiEvents\Models\OrderItem;
use HiEvents\Models\Organizer;
use HiEvents\Models\Product;
use HiEvents\Models\ProductCategory;
use HiEvents\Models\ProductPrice;
use HiEvents\Models\User;
use Illuminate\Support\Str;

/**
 * One tenant = user + account + organizer + LIVE event + one ticket + one completed order.
 * Tests build two of these to prove nothing crosses between them.
 */
final class AssistantFixture
{
    public User $user;
    public Account $account;
    public Organizer $organizer;
    public Event $event;
    public Product $product;
    public ProductPrice $price;
    public Order $order;
    public string $password = 'password123';

    public static function create(string $label): self
    {
        AccountConfiguration::firstOrCreate(['id' => 1], [
            'id' => 1,
            'name' => 'Default',
            'is_system_default' => true,
            'application_fees' => ['percentage' => 1.5, 'fixed' => 0],
        ]);

        $fixture = new self();

        // UserFactory picks a random supported locale and SetUserLocaleMiddleware
        // applies it to every request, so any assertion on a translated API
        // message is flaky unless the user's locale is pinned.
        $fixture->user = User::factory()
            ->password($fixture->password)
            ->withAccount()
            ->create(['locale' => 'en']);
        $fixture->account = $fixture->user->accounts()->first();

        $fixture->organizer = Organizer::create([
            'account_id' => $fixture->account->id,
            'name' => 'Org ' . $label,
            'email' => 'org-' . Str::lower($label) . '@test.passix',
            'currency' => 'ARS',
            'timezone' => 'America/Argentina/Buenos_Aires',
        ]);

        // Event::creating reads auth()->user(); user_id is set explicitly instead.
        $fixture->event = Event::withoutEvents(static fn(): Event => Event::create([
            'title' => 'Evento ' . $label,
            'account_id' => $fixture->account->id,
            'organizer_id' => $fixture->organizer->id,
            'user_id' => $fixture->user->id,
            'status' => 'LIVE',
            'start_date' => now()->addMonth(),
            'end_date' => now()->addMonth()->addHours(3),
            'currency' => 'ARS',
            'timezone' => 'America/Argentina/Buenos_Aires',
            'short_id' => Str::random(8),
        ]));

        $category = ProductCategory::create([
            'name' => 'Entradas',
            'event_id' => $fixture->event->id,
            'order' => 0,
            'is_hidden' => false,
        ]);

        $fixture->product = Product::create([
            'title' => 'General ' . $label,
            'event_id' => $fixture->event->id,
            'product_category_id' => $category->id,
            'type' => 'PAID',
            'product_type' => 'TICKET',
            'order' => 0,
            'is_hidden' => false,
        ]);

        $fixture->price = ProductPrice::create([
            'product_id' => $fixture->product->id,
            'price' => 5000.00,
            'initial_quantity_available' => 100,
            'quantity_available' => 98,
            'quantity_sold' => 2,
            'is_hidden' => false,
            'order' => 0,
        ]);

        $fixture->order = Order::create([
            'event_id' => $fixture->event->id,
            'short_id' => IdHelper::shortId(IdHelper::ORDER_PREFIX),
            'public_id' => 'PUB-' . Str::upper($label),
            'session_id' => Str::random(40),
            'status' => OrderStatus::COMPLETED->name,
            'payment_status' => OrderPaymentStatus::PAYMENT_RECEIVED->name,
            'currency' => 'ARS',
            'total_before_additions' => 10000,
            'total_gross' => 10000,
            'total_tax' => 0,
            'total_fee' => 0,
            'first_name' => 'Comprador',
            'last_name' => $label,
            'email' => 'buyer-' . Str::lower($label) . '@test.passix',
            'notes' => 'SECRET NOTE ' . $label,
        ]);

        OrderItem::create([
            'order_id' => $fixture->order->id,
            'product_id' => $fixture->product->id,
            'product_price_id' => $fixture->price->id,
            'item_name' => $fixture->product->title,
            'quantity' => 2,
            'price' => 5000,
            'total_before_additions' => 10000,
            'total_gross' => 10000,
        ]);

        EventStatistic::create([
            'event_id' => $fixture->event->id,
            'products_sold' => 2,
            'orders_created' => 1,
            'sales_total_gross' => 10000,
            'sales_total_before_additions' => 10000,
            'total_tax' => 0,
            'total_fee' => 0,
            'total_views' => 10,
            'total_refunded' => 0,
            'attendees_registered' => 2,
        ]);

        return $fixture;
    }
}
