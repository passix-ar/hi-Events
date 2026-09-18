<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use HiEvents\Assistant\Domain\AssistantContext;
use HiEvents\Assistant\Domain\AssistantContextFactory;
use HiEvents\Assistant\Domain\Tools\CreateDraftEventTool;
use HiEvents\Assistant\Domain\Tools\CreateTicketTool;
use HiEvents\Assistant\Domain\Tools\DeleteEventTool;
use HiEvents\Assistant\Domain\Tools\DeleteTicketTool;
use HiEvents\Assistant\Domain\Tools\UpdateEventTool;
use HiEvents\Assistant\Domain\Tools\UpdateTicketTool;
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
 * Edits and deletions carry a double check: a plain yes on a draft, the exact
 * word MODIFICAR on a published event, the exact word ELIMINAR to delete.
 */
class AssistantEditDeleteTest extends TestCase
{
    use DatabaseTransactions;

    private AssistantFixture $mine;
    private AssistantFixture $theirs;
    private AssistantContext $context;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake((string)config('filesystems.public'));
        $this->mine = AssistantFixture::create('A');
        $this->theirs = AssistantFixture::create('B');
        $this->actingAs($this->mine->user, 'api');

        $user = UserDomainObject::hydrateFromModel($this->mine->user);
        $user->setCurrentAccountUser($this->app->make(AccountUserRepositoryInterface::class)->findFirstWhere([
            'user_id' => $this->mine->user->id,
            'account_id' => $this->mine->account->id,
        ]));
        $this->context = $this->app->make(AssistantContextFactory::class)->create(
            user: $user, accountId: $this->mine->account->id, organizerId: $this->mine->organizer->id,
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

    /** @return array{0:int,1:int} event id, product id */
    private function draftWithTicket(string $title = 'Borrador Editable'): array
    {
        $eventId = $this->runTool($this->tool(CreateDraftEventTool::class), title: $title, start_date: '2026-12-20 22:00', end_date: '2026-12-21 04:00', confirm: true)['event']['id'];
        $productId = $this->runTool($this->tool(CreateTicketTool::class), event_id: $eventId, title: 'General', price: 5000, quantity: 100, confirm: true)['ticket']['id'];

        return [$eventId, $productId];
    }

    // ── update_ticket ─────────────────────────────────────────────────────

    public function test_ticket_preview_changes_nothing(): void
    {
        [$eventId, $productId] = $this->draftWithTicket();

        $result = $this->runTool($this->tool(UpdateTicketTool::class), event_id: $eventId, product_id: $productId, price: 7000);

        $this->assertSame('needs_confirmation', $result['status']);
        $this->assertSame(7000.0, $result['would_create']['changes']['price']);
        $this->assertEquals(5000, (float)ProductPrice::where('product_id', $productId)->first()->price);
    }

    public function test_ticket_on_a_draft_changes_with_a_plain_confirmation(): void
    {
        [$eventId, $productId] = $this->draftWithTicket();

        $result = $this->runTool($this->tool(UpdateTicketTool::class), event_id: $eventId, product_id: $productId, price: 7000, quantity: 80, title: 'General Plus', confirm: true);

        $this->assertSame('updated', $result['status']);
        $price = ProductPrice::where('product_id', $productId)->first();
        $this->assertEquals(7000, (float)$price->price);
        $this->assertSame(80, $price->initial_quantity_available);
        $this->assertSame('General Plus', Product::find($productId)->title);
    }

    public function test_ticket_on_a_published_event_needs_the_word_modificar(): void
    {
        $productId = $this->mine->product->id; // fixture event is LIVE, price 5000

        $refused = $this->runTool($this->tool(UpdateTicketTool::class), event_id: $this->mine->event->id, product_id: $productId, price: 9000, confirm: true);
        $this->assertSame('confirmation_phrase_required', $refused['error']);
        $this->assertEquals(5000, (float)ProductPrice::where('product_id', $productId)->first()->price);

        $done = $this->runTool($this->tool(UpdateTicketTool::class), event_id: $this->mine->event->id, product_id: $productId, price: 9000, confirm: true, confirmation_phrase: 'MODIFICAR');
        $this->assertSame('updated', $done['status']);
        $this->assertEquals(9000, (float)ProductPrice::where('product_id', $productId)->first()->price);
    }

    public function test_ticket_of_another_tenant_is_not_found(): void
    {
        $result = $this->runTool($this->tool(UpdateTicketTool::class), event_id: $this->theirs->event->id, product_id: $this->theirs->product->id, price: 1, confirm: true, confirmation_phrase: 'MODIFICAR');
        $this->assertSame(['error' => 'event_not_found'], $result);

        // A product id from another event, on my own event, is not found either.
        $cross = $this->runTool($this->tool(UpdateTicketTool::class), event_id: $this->mine->event->id, product_id: $this->theirs->product->id, price: 1, confirm: true, confirmation_phrase: 'MODIFICAR');
        $this->assertSame('ticket_not_found', $cross['error']);
    }

    // ── update_event ──────────────────────────────────────────────────────

    public function test_event_dates_and_title_change_on_a_draft(): void
    {
        [$eventId] = $this->draftWithTicket('Fecha Vieja');

        $result = $this->runTool($this->tool(UpdateEventTool::class), event_id: $eventId, title: 'Fecha Nueva', start_date: '2026-12-27 21:00', confirm: true);

        $this->assertSame('updated', $result['status'] ?? json_encode($result));
        $event = Event::find($eventId);
        $this->assertSame('Fecha Nueva', $event->title);
        $this->assertSame('2026-12-27 21:00', Carbon::parse($event->start_date, 'UTC')->setTimezone($event->timezone)->format('Y-m-d H:i'));
        // Moving the start by 7 days moves the end by 7 days too: the event keeps its duration.
        $this->assertSame('2026-12-28 03:00', Carbon::parse($event->end_date, 'UTC')->setTimezone($event->timezone)->format('Y-m-d H:i'));
        $this->assertSame('2026-12-28 03:00', $result['applied']['end_date']);
    }

    public function test_event_on_a_published_event_needs_the_word_modificar(): void
    {
        $refused = $this->runTool($this->tool(UpdateEventTool::class), event_id: $this->mine->event->id, title: 'Otro', confirm: true);
        $this->assertSame('confirmation_phrase_required', $refused['error']);
        $this->assertSame('Evento A', Event::find($this->mine->event->id)->title);

        $done = $this->runTool($this->tool(UpdateEventTool::class), event_id: $this->mine->event->id, title: 'Evento A Renombrado', confirm: true, confirmation_phrase: 'MODIFICAR');
        $this->assertSame('updated', $done['status']);
        $this->assertSame('Evento A Renombrado', Event::find($this->mine->event->id)->title);
    }

    public function test_event_edit_without_changes_or_cross_tenant_is_rejected(): void
    {
        $none = $this->runTool($this->tool(UpdateEventTool::class), event_id: $this->mine->event->id, confirm: true, confirmation_phrase: 'MODIFICAR');
        $this->assertSame('invalid_arguments', $none['error']);

        $foreign = $this->runTool($this->tool(UpdateEventTool::class), event_id: $this->theirs->event->id, title: 'x', confirm: true, confirmation_phrase: 'MODIFICAR');
        $this->assertSame(['error' => 'event_not_found'], $foreign);
    }

    // ── delete_ticket ─────────────────────────────────────────────────────

    public function test_deleting_a_ticket_needs_eliminar_and_then_removes_it(): void
    {
        [$eventId, $productId] = $this->draftWithTicket('Con Entrada Borrable');

        $preview = $this->runTool($this->tool(DeleteTicketTool::class), event_id: $eventId, product_id: $productId);
        $this->assertSame('needs_confirmation', $preview['status']);

        $refused = $this->runTool($this->tool(DeleteTicketTool::class), event_id: $eventId, product_id: $productId, confirm: true);
        $this->assertSame('confirmation_phrase_required', $refused['error']);
        $this->assertNotNull(Product::find($productId));

        $done = $this->runTool($this->tool(DeleteTicketTool::class), event_id: $eventId, product_id: $productId, confirm: true, confirmation_phrase: 'ELIMINAR');
        $this->assertSame('deleted', $done['status']);
        $this->assertNull(Product::find($productId));
    }

    public function test_a_ticket_with_sales_cannot_be_deleted(): void
    {
        // The fixture ticket has a completed order.
        $result = $this->runTool($this->tool(DeleteTicketTool::class), event_id: $this->mine->event->id, product_id: $this->mine->product->id, confirm: true, confirmation_phrase: 'ELIMINAR');

        $this->assertSame('cannot_delete', $result['error']);
        $this->assertNotNull(Product::find($this->mine->product->id));
    }

    // ── delete_event ──────────────────────────────────────────────────────

    public function test_deleting_an_event_needs_eliminar(): void
    {
        [$eventId] = $this->draftWithTicket('Evento Borrable');

        $refused = $this->runTool($this->tool(DeleteEventTool::class), event_id: $eventId, confirm: true, confirmation_phrase: 'eliminar');
        $this->assertSame('confirmation_phrase_required', $refused['error']);
        $this->assertNotNull(Event::find($eventId));

        $done = $this->runTool($this->tool(DeleteEventTool::class), event_id: $eventId, confirm: true, confirmation_phrase: 'ELIMINAR');
        $this->assertSame('deleted', $done['status']);
        $this->assertNull(Event::find($eventId));
    }

    public function test_an_event_with_orders_cannot_be_deleted(): void
    {
        $result = $this->runTool($this->tool(DeleteEventTool::class), event_id: $this->mine->event->id, confirm: true, confirmation_phrase: 'ELIMINAR');

        $this->assertSame('cannot_delete', $result['error']);
        $this->assertNotNull(Event::find($this->mine->event->id));
    }

    public function test_another_tenants_event_cannot_be_deleted(): void
    {
        $result = $this->runTool($this->tool(DeleteEventTool::class), event_id: $this->theirs->event->id, confirm: true, confirmation_phrase: 'ELIMINAR');

        $this->assertSame(['error' => 'event_not_found'], $result);
        $this->assertNotNull(Event::find($this->theirs->event->id));
    }
}
