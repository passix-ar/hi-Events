<?php

declare(strict_types=1);

namespace Tests\Unit\Assistant\Tools;

use HiEvents\Assistant\Domain\Tools\GetTicketRankingTool;
use HiEvents\Repository\Interfaces\AccountUserRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Application\Handlers\Reports\DTO\GetReportDTO;
use HiEvents\Services\Application\Handlers\Reports\GetReportHandler;
use HiEvents\Services\Infrastructure\Authorization\IsAuthorizedService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Mockery;
use Mockery\MockInterface;
use Psr\Log\NullLogger;
use Tests\TestCase;
use Tests\Unit\Assistant\AssistantTestHelpers;

/**
 * The tool is the last line of defence between the model and another tenant's
 * data, so these tests exercise the real IsAuthorizedService with mocked
 * repositories rather than mocking the authorization itself.
 */
class GetTicketRankingToolTest extends TestCase
{
    use AssistantTestHelpers;

    private EventRepositoryInterface|MockInterface $events;
    private GetReportHandler|MockInterface $reports;

    protected function setUp(): void
    {
        parent::setUp();

        $this->events = Mockery::mock(EventRepositoryInterface::class);
        $this->reports = Mockery::mock(GetReportHandler::class);

        $this->app->instance(EventRepositoryInterface::class, $this->events);
        $this->app->instance(AccountUserRepositoryInterface::class, Mockery::mock(AccountUserRepositoryInterface::class));
    }

    private function makeTool(int $accountId = 1, int $organizerId = 10): GetTicketRankingTool
    {
        return new GetTicketRankingTool(
            context: $this->makeContext(accountId: $accountId, organizerId: $organizerId),
            isAuthorizedService: $this->app->make(IsAuthorizedService::class),
            events: $this->events,
            logger: new NullLogger(),
            reports: $this->reports,
        );
    }

    public function test_event_of_another_account_is_reported_as_not_found_and_report_is_never_run(): void
    {
        $this->events->shouldReceive('findById')->with(42)->andReturn($this->makeEvent(42, accountId: 2, organizerId: 10));
        $this->events->shouldNotReceive('findFirstWhere');
        $this->reports->shouldNotReceive('handle');

        $output = $this->runTool($this->makeTool(), event_id: 42);

        $this->assertSame(['error' => 'event_not_found'], json_decode($output, true));
    }

    public function test_event_of_another_organizer_in_the_same_account_is_reported_as_not_found(): void
    {
        $this->events->shouldReceive('findById')->with(42)->andReturn($this->makeEvent(42, accountId: 1, organizerId: 99));
        $this->events->shouldReceive('findFirstWhere')
            ->with(['id' => 42, 'account_id' => 1, 'organizer_id' => 10])
            ->andReturnNull();
        $this->reports->shouldNotReceive('handle');

        $output = $this->runTool($this->makeTool(), event_id: 42);

        $this->assertSame(['error' => 'event_not_found'], json_decode($output, true));
    }

    public function test_missing_event_is_reported_as_not_found(): void
    {
        $this->events->shouldReceive('findById')->with(42)->andThrow(new ModelNotFoundException());
        $this->reports->shouldNotReceive('handle');

        $output = $this->runTool($this->makeTool(), event_id: 42);

        $this->assertSame(['error' => 'event_not_found'], json_decode($output, true));
    }

    public function test_invalid_arguments_are_echoed_back_without_touching_data(): void
    {
        $this->events->shouldNotReceive('findById');
        $this->reports->shouldNotReceive('handle');

        $output = json_decode($this->runTool($this->makeTool(), event_id: 0), true);

        $this->assertSame('invalid_arguments', $output['error']);
        $this->assertArrayHasKey('details', $output);
    }

    public function test_date_range_over_the_limit_is_rejected(): void
    {
        $event = $this->makeEvent(42, accountId: 1, organizerId: 10);
        $this->events->shouldReceive('findById')->with(42)->andReturn($event);
        $this->events->shouldReceive('findFirstWhere')->andReturn($event);
        $this->reports->shouldNotReceive('handle');

        $output = json_decode($this->runTool($this->makeTool(), event_id: 42, start_date: '2024-01-01', end_date: '2026-01-01'), true);

        $this->assertSame('invalid_arguments', $output['error']);
    }

    public function test_ranking_is_sorted_by_units_sold_and_only_exposes_safe_fields(): void
    {
        $event = $this->makeEvent(42, accountId: 1, organizerId: 10, title: 'Fiesta de fin de año');
        $this->events->shouldReceive('findById')->with(42)->andReturn($event);
        $this->events->shouldReceive('findFirstWhere')->andReturn($event);

        $this->reports->shouldReceive('handle')
            ->once()
            ->withArgs(fn(GetReportDTO $dto): bool => $dto->eventId === 42)
            ->andReturn(collect([
                (object)['product_id' => 1, 'product_title' => 'General', 'product_type' => 'TICKET', 'number_sold' => 5, 'total_gross' => '5000.00'],
                (object)['product_id' => 2, 'product_title' => 'VIP', 'product_type' => 'TICKET', 'number_sold' => 12, 'total_gross' => '36000.00'],
            ]));

        $output = json_decode($this->runTool($this->makeTool(), event_id: 42), true);

        $this->assertSame(42, $output['event_id']);
        $this->assertSame(17, $output['total_sold']);
        $this->assertSame(['VIP', 'General'], array_column($output['ranking'], 'title'));
        $this->assertSame(36000.0, $output['ranking'][0]['gross_revenue']);
        $this->assertSame(['product_id', 'title', 'type', 'sold', 'gross_revenue'], array_keys($output['ranking'][0]));
    }

    public function test_unexpected_failures_are_opaque_to_the_model(): void
    {
        $event = $this->makeEvent(42, accountId: 1, organizerId: 10);
        $this->events->shouldReceive('findById')->with(42)->andReturn($event);
        $this->events->shouldReceive('findFirstWhere')->andReturn($event);
        $this->reports->shouldReceive('handle')->andThrow(new \RuntimeException('SQLSTATE[42P01] relation "secret" does not exist'));

        $output = $this->runTool($this->makeTool(), event_id: 42);

        $this->assertSame(['error' => 'tool_failed'], json_decode($output, true));
        $this->assertStringNotContainsString('SQLSTATE', $output);
    }
}
