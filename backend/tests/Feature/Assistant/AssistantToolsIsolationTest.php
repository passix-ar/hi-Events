<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use HiEvents\Assistant\Domain\AssistantContext;
use HiEvents\Assistant\Domain\AssistantContextFactory;
use HiEvents\Assistant\Domain\AssistantToolRegistry;
use HiEvents\Assistant\Domain\Tools\FindEventsTool;
use HiEvents\Assistant\Domain\Tools\GetEventStatsTool;
use HiEvents\Assistant\Domain\Tools\GetOrganizerStatsTool;
use HiEvents\Assistant\Domain\Tools\GetRecentOrdersTool;
use HiEvents\Assistant\Domain\Tools\GetTicketRankingTool;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Repository\Interfaces\AccountUserRepositoryInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolError;
use Tests\TestCase;

/**
 * Runs every tool against the real database and the real authorization
 * service, from tenant A's context, and checks that tenant B never shows up.
 */
class AssistantToolsIsolationTest extends TestCase
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
            user: $user,
            accountId: $this->mine->account->id,
            organizerId: $this->mine->organizer->id,
        );
    }

    /**
     * @template T of Tool
     * @param class-string<T> $class
     * @return T
     */
    private function tool(string $class): Tool
    {
        return $this->app->make($class, ['context' => $this->context]);
    }

    private function runTool(Tool $tool, mixed ...$arguments): array
    {
        $output = $tool->handle(...$arguments);
        $json = $output instanceof ToolError ? $output->message : (string)$output;

        return json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    }

    public function test_registry_builds_all_tools_for_a_context(): void
    {
        $tools = $this->app->make(AssistantToolRegistry::class)->forContext($this->context);

        $this->assertSame(AssistantToolRegistry::toolNames(), array_map(fn(Tool $t) => $t->name(), $tools));
    }

    public function test_find_events_only_lists_my_organizers_events(): void
    {
        $result = $this->runTool($this->tool(FindEventsTool::class), period: 'all');

        $this->assertSame(1, $result['total']);
        $this->assertSame($this->mine->event->id, $result['events'][0]['id']);
        $this->assertSame('Evento A', $result['events'][0]['title']);

        $bySearch = $this->runTool($this->tool(FindEventsTool::class), query: 'Evento B', period: 'all');
        $this->assertSame(0, $bySearch['total']);
    }

    public function test_recent_orders_are_scoped_and_strip_buyer_pii(): void
    {
        $result = $this->runTool($this->tool(GetRecentOrdersTool::class));

        $this->assertSame(1, $result['total']);
        $this->assertSame('PUB-A', $result['orders'][0]['public_id']);
        $this->assertSame('Evento A', $result['orders'][0]['event_title']);
        $this->assertSame(2, $result['orders'][0]['items_count']);
        $this->assertSame(10000.0, $result['orders'][0]['total_gross']);

        $raw = json_encode($result);
        $this->assertStringNotContainsString('test.passix', $raw);
        $this->assertStringNotContainsString('SECRET NOTE', $raw);
        $this->assertStringNotContainsString('PUB-B', $raw);
    }

    public function test_recent_orders_can_filter_by_status_within_an_event(): void
    {
        $eventId = $this->mine->event->id;
        $completed = $this->runTool($this->tool(GetRecentOrdersTool::class), event_id: $eventId, status: 'COMPLETED');
        $cancelled = $this->runTool($this->tool(GetRecentOrdersTool::class), event_id: $eventId, status: 'CANCELLED');
        $organizerWide = $this->runTool($this->tool(GetRecentOrdersTool::class), status: 'COMPLETED');

        $this->assertSame(1, $completed['total']);
        $this->assertSame(0, $cancelled['total']);
        $this->assertSame('invalid_arguments', $organizerWide['error']);
    }

    public function test_recent_orders_for_another_tenants_event_are_not_found(): void
    {
        $result = $this->runTool($this->tool(GetRecentOrdersTool::class), event_id: $this->theirs->event->id);

        $this->assertSame(['error' => 'event_not_found'], $result);
    }

    public function test_organizer_stats_only_sum_my_events(): void
    {
        $result = $this->runTool($this->tool(GetOrganizerStatsTool::class));

        $this->assertSame('ARS', $result['currency']);
        $this->assertSame(10000.0, $result['all_time']['gross_sales']);
        $this->assertSame(2, $result['all_time']['tickets_sold']);
        $this->assertCount(1, $result['events']);
        $this->assertSame($this->mine->event->id, $result['events'][0]['event_id']);
        $this->assertSame(10000.0, $result['events'][0]['gross_revenue']);
    }

    public function test_organizer_stats_with_a_period_returns_period_totals(): void
    {
        $result = $this->runTool(
            $this->tool(GetOrganizerStatsTool::class),
            start_date: now()->subDays(7)->toDateString(),
            end_date: now()->toDateString(),
        );

        $this->assertArrayHasKey('period', $result);
        $this->assertSame(now()->toDateString(), $result['period']['end_date']);
        $this->assertArrayHasKey('gross_sales', $result['period']);
    }

    public function test_event_stats_for_my_event_include_availability_and_check_in(): void
    {
        $result = $this->runTool($this->tool(GetEventStatsTool::class), event_id: $this->mine->event->id);

        $this->assertSame('Evento A', $result['event']['title']);
        $this->assertSame(10000.0, $result['totals']['gross_sales']);
        $this->assertSame(2, $result['totals']['tickets_sold']);
        $this->assertSame(0, $result['check_in']['checked_in']);
        $this->assertCount(1, $result['tickets']);
        $this->assertSame('General A', $result['tickets'][0]['title']);
        $this->assertSame(98, $result['tickets'][0]['available']);
        $this->assertSame(100, $result['tickets'][0]['initial_capacity']);
    }

    public function test_event_stats_for_another_tenants_event_are_not_found(): void
    {
        $result = $this->runTool($this->tool(GetEventStatsTool::class), event_id: $this->theirs->event->id);

        $this->assertSame(['error' => 'event_not_found'], $result);
    }

    public function test_ticket_ranking_for_my_event(): void
    {
        $result = $this->runTool(
            $this->tool(GetTicketRankingTool::class),
            event_id: $this->mine->event->id,
            start_date: now()->subDay()->toDateString(),
            end_date: now()->addDay()->toDateString(),
        );

        $this->assertSame(2, $result['total_sold']);
        $this->assertSame('General A', $result['ranking'][0]['title']);
        $this->assertSame(10000.0, $result['ranking'][0]['gross_revenue']);
    }

    public function test_ticket_ranking_for_another_tenants_event_is_not_found(): void
    {
        $result = $this->runTool($this->tool(GetTicketRankingTool::class), event_id: $this->theirs->event->id);

        $this->assertSame(['error' => 'event_not_found'], $result);
    }
}
