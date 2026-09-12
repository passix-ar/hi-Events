<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use HiEvents\Assistant\Domain\AssistantContext;
use HiEvents\Assistant\Domain\AssistantContextFactory;
use HiEvents\Assistant\Domain\AssistantToolRegistry;
use HiEvents\Assistant\Domain\Tools\CreateDraftEventTool;
use HiEvents\Assistant\Domain\Tools\CreateTicketTool;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Models\Event;
use HiEvents\Models\Product;
use HiEvents\Models\ProductPrice;
use HiEvents\Repository\Interfaces\AccountUserRepositoryInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolError;
use Tests\TestCase;

/**
 * The write tools are the only ones that can leave a mark, so these tests are
 * about the three guarantees that make them safe: nothing is created without an
 * explicit confirmation, nothing created is public, and nothing crosses into
 * another tenant.
 */
class AssistantWriteToolsTest extends TestCase
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

        // Event::creating reads auth()->user(); the tools only ever run inside an
        // authenticated request, so the test has to stand in the same place.
        $this->actingAs($this->mine->user, 'api');

        // CreateEventService asks object storage whether a default cover exists for
        // the category, and lets the exception through if the bucket is unreachable.
        // Faking the disk keeps this test about the tools rather than about MinIO.
        Storage::fake((string)config('filesystems.public'));

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

    // ── create_draft_event ────────────────────────────────────────────────

    public function test_without_confirmation_nothing_is_created(): void
    {
        $before = Event::count();

        $result = $this->runTool(
            $this->tool(CreateDraftEventTool::class),
            title: 'Fiesta de Prueba',
            start_date: '2026-12-20 22:00',
        );

        $this->assertSame('needs_confirmation', $result['status']);
        $this->assertSame('Fiesta de Prueba', $result['would_create']['title']);
        $this->assertSame('DRAFT', $result['would_create']['status']);
        $this->assertSame($before, Event::count(), 'the preview must not touch the database');
    }

    public function test_confirmed_creation_makes_a_draft_owned_by_this_organizer(): void
    {
        $result = $this->runTool(
            $this->tool(CreateDraftEventTool::class),
            title: 'Fiesta Confirmada',
            start_date: '2026-12-20 22:00',
            end_date: '2026-12-21 04:00',
            description: 'Una noche larga',
            category: 'MUSIC',
            venue_name: 'Club Passix',
            city: 'Buenos Aires',
            confirm: true,
        );

        $this->assertSame('created', $result['status']);

        $event = Event::find($result['event']['id']);

        $this->assertNotNull($event);
        $this->assertSame(EventStatus::DRAFT->name, $event->status, 'a chat must never publish an event');
        $this->assertSame($this->mine->organizer->id, $event->organizer_id);
        $this->assertSame($this->mine->account->id, $event->account_id);
        $this->assertSame($this->mine->user->id, $event->user_id);
        $this->assertSame('ARS', $event->currency, 'currency comes from the organizer, not the model');
        $this->assertSame('America/Argentina/Buenos_Aires', $event->timezone);
        // CreateEventService stores UTC via DateHelper::convertToUTC, so read it
        // back in the organizer timezone to check the hour the organizer asked for.
        $this->assertSame(
            '2026-12-20 22:00',
            Carbon::parse($event->start_date, 'UTC')
                ->setTimezone($this->mine->organizer->timezone)
                ->format('Y-m-d H:i'),
        );
    }

    public function test_asking_twice_does_not_create_a_second_event(): void
    {
        $first = $this->runTool(
            $this->tool(CreateDraftEventTool::class),
            title: 'Fiesta Repetida',
            start_date: '2026-12-20 22:00',
            confirm: true,
        );

        $countAfterFirst = Event::count();

        $second = $this->runTool(
            $this->tool(CreateDraftEventTool::class),
            title: 'Fiesta Repetida',
            start_date: '2026-12-20 23:30',
            confirm: true,
        );

        $this->assertSame('created', $first['status']);
        $this->assertSame('already_exists', $second['status']);
        $this->assertSame($first['event']['id'], $second['event']['id']);
        $this->assertSame($countAfterFirst, Event::count());
    }

    public function test_an_event_gets_a_ticket_category_so_tickets_can_be_added(): void
    {
        $event = $this->runTool(
            $this->tool(CreateDraftEventTool::class),
            title: 'Fiesta Con Categoria',
            start_date: '2026-12-20 22:00',
            confirm: true,
        );

        $ticket = $this->runTool(
            $this->tool(CreateTicketTool::class),
            event_id: $event['event']['id'],
            title: 'General',
            price: 5000,
            quantity: 100,
            confirm: true,
        );

        $this->assertSame('created', $ticket['status']);
    }

    public function test_a_malformed_date_is_rejected_before_anything_is_written(): void
    {
        $before = Event::count();

        $result = $this->runTool(
            $this->tool(CreateDraftEventTool::class),
            title: 'Fecha Rara',
            start_date: 'el viernes que viene',
            confirm: true,
        );

        $this->assertSame('invalid_arguments', $result['error']);
        $this->assertSame($before, Event::count());
    }

    public function test_an_end_date_before_the_start_is_rejected(): void
    {
        $before = Event::count();

        $result = $this->runTool(
            $this->tool(CreateDraftEventTool::class),
            title: 'Al Reves',
            start_date: '2026-12-20 22:00',
            end_date: '2026-12-19 22:00',
            confirm: true,
        );

        $this->assertSame('invalid_arguments', $result['error']);
        $this->assertSame($before, Event::count());
    }

    // ── create_ticket ─────────────────────────────────────────────────────

    public function test_ticket_preview_creates_nothing(): void
    {
        $before = Product::count();

        $result = $this->runTool(
            $this->tool(CreateTicketTool::class),
            event_id: $this->mine->event->id,
            title: 'Platea',
            price: 12000,
        );

        $this->assertSame('needs_confirmation', $result['status']);
        $this->assertSame('PAID', $result['would_create']['type']);
        $this->assertSame('ARS', $result['would_create']['currency']);
        $this->assertSame($before, Product::count());
    }

    public function test_a_confirmed_ticket_is_created_with_its_price_and_stock(): void
    {
        $result = $this->runTool(
            $this->tool(CreateTicketTool::class),
            event_id: $this->mine->event->id,
            title: 'Platea',
            price: 12000.5,
            quantity: 80,
            description: 'Incluye una bebida',
            confirm: true,
        );

        $this->assertSame('created', $result['status']);

        $product = Product::find($result['ticket']['id']);
        $price = ProductPrice::where('product_id', $product->id)->first();

        $this->assertSame($this->mine->event->id, $product->event_id);
        $this->assertSame('PAID', $product->type);
        $this->assertSame('TICKET', $product->product_type);
        $this->assertEquals(12000.5, (float)$price->price);
        $this->assertSame(80, $price->initial_quantity_available);
    }

    public function test_a_zero_price_creates_a_free_ticket_with_unlimited_stock(): void
    {
        $result = $this->runTool(
            $this->tool(CreateTicketTool::class),
            event_id: $this->mine->event->id,
            title: 'Invitación',
            price: 0,
            confirm: true,
        );

        $product = Product::find($result['ticket']['id']);
        $price = ProductPrice::where('product_id', $product->id)->first();

        $this->assertSame('FREE', $product->type);
        $this->assertEquals(0.0, (float)$price->price);
        $this->assertNull($price->initial_quantity_available);
        $this->assertSame('unlimited', $result['ticket']['quantity']);
    }

    public function test_a_duplicate_ticket_name_is_not_created_twice(): void
    {
        $this->runTool(
            $this->tool(CreateTicketTool::class),
            event_id: $this->mine->event->id,
            title: 'Unica',
            price: 1000,
            confirm: true,
        );

        $count = Product::where('event_id', $this->mine->event->id)->count();

        $second = $this->runTool(
            $this->tool(CreateTicketTool::class),
            event_id: $this->mine->event->id,
            title: 'Unica',
            price: 9999,
            confirm: true,
        );

        $this->assertSame('already_exists', $second['status']);
        $this->assertSame($count, Product::where('event_id', $this->mine->event->id)->count());
    }

    public function test_a_negative_price_is_rejected(): void
    {
        $result = $this->runTool(
            $this->tool(CreateTicketTool::class),
            event_id: $this->mine->event->id,
            title: 'Precio Negativo',
            price: -500,
            confirm: true,
        );

        $this->assertSame('invalid_arguments', $result['error']);
    }

    // ── tenant isolation ──────────────────────────────────────────────────

    public function test_a_ticket_cannot_be_added_to_another_tenants_event(): void
    {
        $before = Product::where('event_id', $this->theirs->event->id)->count();

        $result = $this->runTool(
            $this->tool(CreateTicketTool::class),
            event_id: $this->theirs->event->id,
            title: 'Entrada Intrusa',
            price: 1000,
            confirm: true,
        );

        $this->assertSame(['error' => 'event_not_found'], $result);
        $this->assertSame($before, Product::where('event_id', $this->theirs->event->id)->count());
    }

    public function test_a_ticket_preview_for_another_tenants_event_leaks_nothing(): void
    {
        $result = $this->runTool(
            $this->tool(CreateTicketTool::class),
            event_id: $this->theirs->event->id,
            title: 'Entrada Intrusa',
            price: 1000,
        );

        $this->assertSame(['error' => 'event_not_found'], $result);
    }

    // ── the flag ──────────────────────────────────────────────────────────

    public function test_the_write_tools_disappear_when_writes_are_disabled(): void
    {
        config()->set('assistant.writes_enabled', false);

        $names = array_map(
            static fn(Tool $tool): string => $tool->name(),
            $this->app->make(AssistantToolRegistry::class)->forContext($this->context),
        );

        $this->assertNotContains('create_draft_event', $names);
        $this->assertNotContains('create_ticket', $names);
        $this->assertContains('get_organizer_stats', $names, 'reading must keep working');
    }

    public function test_all_tools_are_offered_when_writes_are_enabled(): void
    {
        config()->set('assistant.writes_enabled', true);

        $names = array_map(
            static fn(Tool $tool): string => $tool->name(),
            $this->app->make(AssistantToolRegistry::class)->forContext($this->context),
        );

        $this->assertSame(AssistantToolRegistry::toolNames(), $names);
    }
}
