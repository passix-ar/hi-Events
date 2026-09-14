<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Domain\Order;

use Carbon\Carbon;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Models\Event;
use HiEvents\Models\Order;
use HiEvents\Models\Organizer;
use HiEvents\Models\User;
use HiEvents\Services\Domain\Order\OrderManagementService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Creates an order through the real service and reads it back from Postgres.
 * Nothing else exercised this path: the other Feature tests insert orders
 * with Model::create and a Carbon object, which bypasses the string the
 * service builds for reserved_until.
 */
class OrderManagementServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_a_new_order_stores_its_reservation_deadline(): void
    {
        $user = User::factory()->password(Str::random(16))->withAccount()->create();
        $this->actingAs($user);
        $account = $user->accounts()->first();
        $organizer = Organizer::create(['account_id' => $account->id, 'name' => 'Org', 'email' => 'org@test.passix', 'currency' => 'ARS', 'timezone' => 'America/Argentina/Buenos_Aires']);
        $event = Event::create(['title' => 'Evento', 'account_id' => $account->id, 'organizer_id' => $organizer->id, 'user_id' => $user->id, 'status' => 'LIVE', 'start_date' => now()->addMonth(), 'end_date' => now()->addMonth()->addHours(3), 'currency' => 'ARS', 'timezone' => 'America/Argentina/Buenos_Aires', 'short_id' => Str::random(8)]);

        // The combination that used to break: English formatting with a
        // Spanish fallback made toString() write "Sep." into the timestamp.
        app()->setLocale('en');
        Carbon::setLocale('en');

        $order = app(OrderManagementService::class)->createNewOrder(
            eventId: $event->id,
            event: (new EventDomainObject)->setId($event->id)->setCurrency('ARS'),
            timeOutMinutes: 15,
            locale: 'en',
            promoCode: null,
        );

        $stored = Order::findOrFail($order->getId());
        $this->assertSame('RESERVED', $stored->status);
        $this->assertTrue(Carbon::parse($stored->reserved_until)->between(now()->addMinutes(14), now()->addMinutes(16)));
    }
}
