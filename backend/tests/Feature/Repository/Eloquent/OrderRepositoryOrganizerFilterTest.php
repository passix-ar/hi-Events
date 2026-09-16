<?php

declare(strict_types=1);

namespace Tests\Feature\Repository\Eloquent;

use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Helper\IdHelper;
use HiEvents\Http\DTO\QueryParamsDTO;
use HiEvents\Models\AccountConfiguration;
use HiEvents\Models\Event;
use HiEvents\Models\Order;
use HiEvents\Models\Organizer;
use HiEvents\Models\User;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * findByOrganizerId joins events, and orders and events both have status and
 * created_at columns: filtering without a table prefix made Postgres reject the
 * query as ambiguous. The filters are exercised through the repository rather
 * than mocked because the bug only exists in the generated SQL.
 */
class OrderRepositoryOrganizerFilterTest extends TestCase
{
    use DatabaseTransactions;

    private Organizer $organizer;
    private int $accountId;

    protected function setUp(): void
    {
        parent::setUp();

        AccountConfiguration::firstOrCreate(['id' => 1], [
            'id' => 1,
            'name' => 'Default',
            'is_system_default' => true,
            'application_fees' => ['percentage' => 1.5, 'fixed' => 0],
        ]);

        $user = User::factory()->password(Str::random(16))->withAccount()->create();
        $account = $user->accounts()->first();
        $this->accountId = $account->id;

        $this->organizer = Organizer::create([
            'account_id' => $account->id,
            'name' => 'Org Filtros',
            'email' => 'filtros@test.passix',
            'currency' => 'ARS',
            'timezone' => 'America/Argentina/Buenos_Aires',
        ]);

        $event = Event::withoutEvents(fn(): Event => Event::create([
            'title' => 'Evento con filtros',
            'account_id' => $account->id,
            'organizer_id' => $this->organizer->id,
            'user_id' => $user->id,
            'status' => 'LIVE',
            'start_date' => now()->addMonth(),
            'currency' => 'ARS',
            'timezone' => 'America/Argentina/Buenos_Aires',
            'short_id' => Str::random(8),
        ]));

        $this->createOrder($event->id, OrderStatus::COMPLETED->name);
        $this->createOrder($event->id, OrderStatus::COMPLETED->name);
        $this->createOrder($event->id, OrderStatus::CANCELLED->name);
    }

    private function createOrder(int $eventId, string $status): void
    {
        Order::create([
            'event_id' => $eventId,
            'short_id' => IdHelper::shortId(IdHelper::ORDER_PREFIX),
            'public_id' => Str::upper(Str::random(10)),
            'session_id' => Str::random(40),
            'status' => $status,
            'payment_status' => OrderPaymentStatus::PAYMENT_RECEIVED->name,
            'currency' => 'ARS',
            'total_before_additions' => 1000,
            'total_gross' => 1000,
            'total_tax' => 0,
            'total_fee' => 0,
            'first_name' => 'Compra',
            'last_name' => 'Dora',
            'email' => 'compradora@test.passix',
        ]);
    }

    private function findFiltered(array $filterFields): int
    {
        return app(OrderRepositoryInterface::class)
            ->findByOrganizerId(
                organizerId: $this->organizer->id,
                accountId: $this->accountId,
                params: QueryParamsDTO::fromArray([
                    'per_page' => 25,
                    'page' => 1,
                    'filter_fields' => $filterFields,
                ]),
            )
            ->total();
    }

    public function test_orders_can_be_filtered_by_status(): void
    {
        $this->assertSame(2, $this->findFiltered([
            OrderDomainObjectAbstract::STATUS => ['eq' => OrderStatus::COMPLETED->name],
        ]));

        $this->assertSame(1, $this->findFiltered([
            OrderDomainObjectAbstract::STATUS => ['eq' => OrderStatus::CANCELLED->name],
        ]));
    }

    public function test_orders_can_be_filtered_by_created_at(): void
    {
        $this->assertSame(3, $this->findFiltered([
            OrderDomainObjectAbstract::CREATED_AT => ['gte' => now()->subDay()->toDateTimeString()],
        ]));

        $this->assertSame(0, $this->findFiltered([
            OrderDomainObjectAbstract::CREATED_AT => ['lt' => now()->subDay()->toDateTimeString()],
        ]));
    }

    public function test_unfiltered_listing_still_excludes_reserved_and_abandoned(): void
    {
        $this->assertSame(3, $this->findFiltered([]));
    }
}
