<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use HiEvents\Assistant\Domain\AssistantContext;
use HiEvents\Assistant\Domain\AssistantContextFactory;
use HiEvents\Assistant\Domain\Tools\GetEventSalesCurveTool;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Models\Event;
use HiEvents\Models\EventDailyStatistic;
use HiEvents\Repository\Interfaces\AccountUserRepositoryInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolError;
use Tests\TestCase;

class AssistantSalesCurveTest extends TestCase
{
    use DatabaseTransactions;

    private AssistantFixture $mine;
    private AssistantFixture $theirs;
    private AssistantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mine = AssistantFixture::create('A');
        $this->theirs = AssistantFixture::create('B');

        $user = UserDomainObject::hydrateFromModel($this->mine->user);
        $user->setCurrentAccountUser($this->app->make(AccountUserRepositoryInterface::class)->findFirstWhere([
            'user_id' => $this->mine->user->id,
            'account_id' => $this->mine->account->id,
        ]));
        $this->context = $this->app->make(AssistantContextFactory::class)->create(
            user: $user, accountId: $this->mine->account->id, organizerId: $this->mine->organizer->id,
        );
    }

    private function curve(mixed ...$arguments): array
    {
        /** @var Tool $tool */
        $tool = $this->app->make(GetEventSalesCurveTool::class, ['context' => $this->context]);
        $output = $tool->handle(...$arguments);
        $json = $output instanceof ToolError ? $output->message : (string)$output;

        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * The daily sales report reads event_daily_statistics, the aggregate the app
     * maintains as orders complete (UpdateEventStatisticsJob), not raw orders.
     */
    private function salesOn(int $daysAgo, int $orders, int $tickets, float $gross): void
    {
        EventDailyStatistic::create([
            'event_id' => $this->mine->event->id,
            'date' => now()->subDays($daysAgo)->toDateString(),
            'orders_created' => $orders,
            'products_sold' => $tickets,
            'sales_total_gross' => $gross,
            'sales_total_before_additions' => $gross,
            'attendees_registered' => $tickets,
        ]);
    }

    public function test_the_curve_reflects_the_days_with_sales_and_the_totals(): void
    {
        // The curve starts at the event's creation, so the event has to predate the orders.
        Event::where('id', $this->mine->event->id)->update(['created_at' => now()->subDays(30)]);

        $this->salesOn(daysAgo: 10, orders: 1, tickets: 3, gross: 15000);
        $this->salesOn(daysAgo: 3, orders: 2, tickets: 3, gross: 15000);

        $result = $this->curve(event_id: $this->mine->event->id);

        $this->assertSame($this->mine->event->id, $result['event']['id']);
        $this->assertSame('day', $result['curve_granularity']);

        $byDate = array_column($result['curve'], null, 'date');
        $this->assertSame(3, $byDate[now()->subDays(10)->toDateString()]['tickets']);
        $this->assertSame(3, $byDate[now()->subDays(3)->toDateString()]['tickets']);

        $this->assertSame(6, $result['summary']['tickets_sold_total']);
        $this->assertSame(3, $result['summary']['orders_total']);
        $this->assertEquals(30000.0, $result['summary']['gross_total']);
        $this->assertSame(2.0, $result['summary']['avg_tickets_per_order']);
        $this->assertSame(now()->subDays(10)->toDateString(), $result['summary']['first_sale_date']);

        $this->assertCount(1, $result['ticket_types']);
        $this->assertSame('General A', $result['ticket_types'][0]['title']);
    }

    public function test_an_event_without_sales_returns_zeros_not_an_error(): void
    {

        $result = $this->curve(event_id: $this->mine->event->id);

        $this->assertSame(0, $result['summary']['tickets_sold_total']);
        $this->assertNull($result['summary']['first_sale_date']);
        $this->assertArrayNotHasKey('error', $result);
    }

    public function test_another_tenants_event_is_not_found(): void
    {
        $this->assertSame(['error' => 'event_not_found'], $this->curve(event_id: $this->theirs->event->id));
    }
}
