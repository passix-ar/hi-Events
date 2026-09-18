<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use HiEvents\Assistant\Domain\AssistantContext;
use HiEvents\Assistant\Domain\AssistantContextFactory;
use HiEvents\Assistant\Domain\Tools\CreateDraftEventTool;
use HiEvents\Assistant\Domain\Tools\CreateSeatingSectionTool;
use HiEvents\Assistant\Domain\Tools\CreateTicketTool;
use HiEvents\Assistant\Domain\Tools\DeleteSeatingSectionTool;
use HiEvents\Assistant\Domain\Tools\GetSeatingSectionsTool;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Models\Event;
use HiEvents\Models\Seat;
use HiEvents\Models\SeatingSection;
use HiEvents\Repository\Interfaces\AccountUserRepositoryInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolError;
use Tests\TestCase;

/**
 * The seat map built sector by sector from the chat, through the same
 * service the seating designer uses: seats generated, capacity limits,
 * MODIFICAR on a live event, ELIMINAR to remove, tenant isolation.
 */
class AssistantSeatingTest extends TestCase
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

    /** @return array{0:int,1:int} event id, ticket id */
    private function draftWithTicket(): array
    {
        $eventId = $this->runTool($this->tool(CreateDraftEventTool::class), title: 'Noche de Jazz', start_date: '2026-12-04 21:00', end_date: '2026-12-05 00:00', confirm: true)['event']['id'];
        $ticketId = $this->runTool($this->tool(CreateTicketTool::class), event_id: $eventId, title: 'Platea', price: 12000, quantity: 600, confirm: true)['ticket']['id'];

        return [$eventId, $ticketId];
    }

    public function test_preview_creates_nothing_and_shows_the_total(): void
    {
        [$eventId, $ticketId] = $this->draftWithTicket();

        $preview = $this->runTool($this->tool(CreateSeatingSectionTool::class), event_id: $eventId, name: 'Platea', ticket_id: $ticketId, rows: 20, seats_per_row: 30);

        $this->assertSame('needs_confirmation', $preview['status']);
        $this->assertSame(600, $preview['would_create']['total_seats']);
        $this->assertSame(0, SeatingSection::where('event_id', $eventId)->count());
    }

    public function test_a_section_generates_its_seats_and_shows_up_in_the_map(): void
    {
        [$eventId, $ticketId] = $this->draftWithTicket();

        $created = $this->runTool($this->tool(CreateSeatingSectionTool::class), event_id: $eventId, name: 'Platea', ticket_id: $ticketId, rows: 20, seats_per_row: 30, aisles_after_seats: [10, 20], confirm: true);

        $this->assertSame('created', $created['status']);
        $sectionId = $created['section']['id'];
        $this->assertSame(600, Seat::where('seating_section_id', $sectionId)->count());

        $map = $this->runTool($this->tool(GetSeatingSectionsTool::class), event_id: $eventId);
        $this->assertTrue($map['has_seat_map']);
        $this->assertSame(600, $map['total_seats']);
        $this->assertSame('Platea', $map['sections'][0]['ticket']);

        $again = $this->runTool($this->tool(CreateSeatingSectionTool::class), event_id: $eventId, name: 'platea', ticket_id: $ticketId, rows: 5, seats_per_row: 5, confirm: true);
        $this->assertSame('already_exists', $again['status']);
        $this->assertSame(1, SeatingSection::where('event_id', $eventId)->count());
    }

    public function test_limits_and_unknown_ticket_are_refused(): void
    {
        [$eventId, $ticketId] = $this->draftWithTicket();

        $tooBig = $this->runTool($this->tool(CreateSeatingSectionTool::class), event_id: $eventId, name: 'Campo', ticket_id: $ticketId, rows: 50, seats_per_row: 50, confirm: true);
        $this->assertSame('invalid_arguments', $tooBig['error']);

        $noTicket = $this->runTool($this->tool(CreateSeatingSectionTool::class), event_id: $eventId, name: 'Campo', ticket_id: $this->theirs->product->id, rows: 5, seats_per_row: 5, confirm: true);
        $this->assertSame('ticket_not_found', $noTicket['error']);
        $this->assertSame(0, SeatingSection::where('event_id', $eventId)->count());
    }

    public function test_live_event_needs_modificar_and_deleting_needs_eliminar(): void
    {
        [$eventId, $ticketId] = $this->draftWithTicket();
        Event::withoutEvents(fn() => Event::where('id', $eventId)->update(['status' => 'LIVE']));

        $refused = $this->runTool($this->tool(CreateSeatingSectionTool::class), event_id: $eventId, name: 'Pullman', ticket_id: $ticketId, rows: 10, seats_per_row: 25, confirm: true);
        $this->assertSame('confirmation_phrase_required', $refused['error']);

        $created = $this->runTool($this->tool(CreateSeatingSectionTool::class), event_id: $eventId, name: 'Pullman', ticket_id: $ticketId, rows: 10, seats_per_row: 25, confirm: true, confirmation_phrase: 'MODIFICAR');
        $this->assertSame('created', $created['status']);
        $sectionId = $created['section']['id'];

        $plain = $this->runTool($this->tool(DeleteSeatingSectionTool::class), event_id: $eventId, section_id: $sectionId, confirm: true);
        $this->assertSame('confirmation_phrase_required', $plain['error']);

        $deleted = $this->runTool($this->tool(DeleteSeatingSectionTool::class), event_id: $eventId, section_id: $sectionId, confirm: true, confirmation_phrase: 'ELIMINAR');
        $this->assertSame('deleted', $deleted['status']);
        $this->assertSame(0, SeatingSection::where('event_id', $eventId)->whereNull('deleted_at')->count());
    }

    public function test_cannot_build_on_another_tenants_event(): void
    {
        $result = $this->runTool($this->tool(CreateSeatingSectionTool::class), event_id: $this->theirs->event->id, name: 'Platea', ticket_id: $this->theirs->product->id, rows: 5, seats_per_row: 5, confirm: true);

        $this->assertSame('event_not_found', $result['error']);
    }
}
