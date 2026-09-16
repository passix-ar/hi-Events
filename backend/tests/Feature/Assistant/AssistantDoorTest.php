<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use HiEvents\Assistant\Domain\AssistantContext;
use HiEvents\Assistant\Domain\AssistantContextFactory;
use HiEvents\Assistant\Domain\Tools\FindAttendeeTool;
use HiEvents\Assistant\Domain\Tools\GetDoorStatusTool;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Helper\IdHelper;
use HiEvents\Models\Attendee;
use HiEvents\Models\AttendeeCheckIn;
use HiEvents\Models\CheckInList;
use HiEvents\Repository\Interfaces\AccountUserRepositoryInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolError;
use Tests\TestCase;

class AssistantDoorTest extends TestCase
{
    use DatabaseTransactions;

    private const SECRET_NOTE = 'SECRET ATTENDEE NOTE';

    private AssistantFixture $mine;
    private AssistantFixture $theirs;
    private AssistantContext $context;

    private Attendee $juan;
    private Attendee $maria;
    private Attendee $cancelled;
    private CheckInList $checkInList;

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

        $this->juan = $this->attendee($this->mine, 'Juan', 'Pérez', 'juan.perez@gmail.com', AttendeeStatus::ACTIVE);
        $this->maria = $this->attendee($this->mine, 'María', 'González', 'maria@hotmail.com', AttendeeStatus::ACTIVE);
        $this->cancelled = $this->attendee($this->mine, 'Pedro', 'Cancelado', 'pedro@test.passix', AttendeeStatus::CANCELLED);

        // Same last name on the other tenant: must never leak across.
        $this->attendee($this->theirs, 'Juana', 'Pérez', 'juana@otro.com', AttendeeStatus::ACTIVE);

        $this->checkInList = CheckInList::create([
            'event_id' => $this->mine->event->id,
            'short_id' => IdHelper::shortId(IdHelper::CHECK_IN_LIST_PREFIX),
            'name' => 'Puerta principal',
        ]);

        AttendeeCheckIn::create([
            'short_id' => IdHelper::shortId(IdHelper::CHECK_IN_PREFIX),
            'check_in_list_id' => $this->checkInList->id,
            'product_id' => $this->mine->product->id,
            'attendee_id' => $this->juan->id,
            'event_id' => $this->mine->event->id,
            'order_id' => $this->mine->order->id,
            'ip_address' => '127.0.0.1',
        ]);
    }

    private function attendee(AssistantFixture $tenant, string $first, string $last, string $email, AttendeeStatus $status): Attendee
    {
        return Attendee::create([
            'order_id' => $tenant->order->id,
            'event_id' => $tenant->event->id,
            'product_id' => $tenant->product->id,
            'product_price_id' => $tenant->price->id,
            'status' => $status->name,
            'first_name' => $first,
            'last_name' => $last,
            'email' => $email,
            'public_id' => Str::random(20),
            'short_id' => IdHelper::shortId(IdHelper::ATTENDEE_PREFIX),
            'notes' => self::SECRET_NOTE,
        ]);
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

    private function assertNothingSensitive(array $result): void
    {
        $json = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        $this->assertStringNotContainsString(self::SECRET_NOTE, $json);
        $this->assertStringNotContainsString('juan.perez@gmail.com', $json);
        $this->assertStringNotContainsString('maria@hotmail.com', $json);
        $this->assertStringNotContainsString('pedro@test.passix', $json);
        $this->assertStringNotContainsString('buyer-a@test.passix', $json);
        $this->assertStringNotContainsString('SECRET NOTE', $json);
    }

    // ── find_attendee ─────────────────────────────────────────────────────

    public function test_partial_last_name_finds_the_right_attendee_with_masked_email(): void
    {
        $result = $this->runTool($this->tool(FindAttendeeTool::class), event_id: $this->mine->event->id, query: 'pér');

        $this->assertSame('attendee', $result['matched_by']);
        $this->assertSame(1, $result['count']);

        $row = $result['attendees'][0];
        $this->assertSame($this->juan->public_id, $row['public_id']);
        $this->assertSame('Juan', $row['first_name']);
        $this->assertSame('Pérez', $row['last_name']);
        $this->assertSame('ju***@gmail.com', $row['email']);
        $this->assertSame('ACTIVE', $row['status']);
        $this->assertSame('General A', $row['ticket']);
        $this->assertTrue($row['checked_in']);
        $this->assertNotNull($row['checked_in_at']);
        $this->assertSame($this->checkInList->id, $row['check_in_list_id']);
        $this->assertSame('PUB-A', $row['order_public_id']);

        $this->assertArrayNotHasKey('notes', $row);
        $this->assertNothingSensitive($result);
    }

    public function test_attendee_without_check_in_row_reports_checked_in_false(): void
    {
        $result = $this->runTool($this->tool(FindAttendeeTool::class), event_id: $this->mine->event->id, query: 'maria@hotmail');

        $this->assertSame(1, $result['count']);
        $this->assertSame('María', $result['attendees'][0]['first_name']);
        $this->assertSame('ma***@hotmail.com', $result['attendees'][0]['email']);
        $this->assertFalse($result['attendees'][0]['checked_in']);
        $this->assertNull($result['attendees'][0]['checked_in_at']);
        $this->assertNothingSensitive($result);
    }

    public function test_order_public_id_falls_back_to_the_order_attendees(): void
    {
        $result = $this->runTool($this->tool(FindAttendeeTool::class), event_id: $this->mine->event->id, query: 'pub-a');

        $this->assertSame('order_public_id', $result['matched_by']);
        $this->assertSame(3, $result['count']);
        $this->assertNothingSensitive($result);
    }

    public function test_nothing_matches_returns_empty_list(): void
    {
        $result = $this->runTool($this->tool(FindAttendeeTool::class), event_id: $this->mine->event->id, query: 'zzzz-nobody');

        $this->assertSame('none', $result['matched_by']);
        $this->assertSame(0, $result['count']);
        $this->assertSame([], $result['attendees']);
    }

    public function test_search_on_another_tenants_event_is_event_not_found(): void
    {
        $result = $this->runTool($this->tool(FindAttendeeTool::class), event_id: $this->theirs->event->id, query: 'Pérez');

        $this->assertSame(['error' => 'event_not_found'], $result);
    }

    public function test_query_shorter_than_two_chars_is_rejected(): void
    {
        $result = $this->runTool($this->tool(FindAttendeeTool::class), event_id: $this->mine->event->id, query: 'a');

        $this->assertSame('invalid_arguments', $result['error']);
    }

    public function test_wildcard_query_does_not_dump_the_whole_list(): void
    {
        foreach (['%%', '__', ' % ', '_%_'] as $wildcard) {
            $result = $this->runTool($this->tool(FindAttendeeTool::class), event_id: $this->mine->event->id, query: $wildcard);

            $this->assertSame('invalid_arguments', $result['error'] ?? null, "'$wildcard' must not list anyone");
        }
    }

    // ── get_door_status ───────────────────────────────────────────────────

    public function test_door_status_totals_and_per_ticket_split(): void
    {
        $result = $this->runTool($this->tool(GetDoorStatusTool::class), event_id: $this->mine->event->id);

        $this->assertSame($this->mine->event->id, $result['event']['id']);
        $this->assertSame(2, $result['totals']['attendees'], 'cancelled attendee must be excluded');
        $this->assertSame(1, $result['totals']['checked_in']);
        $this->assertSame(1, $result['totals']['pending']);
        $this->assertSame(50.0, $result['totals']['check_in_rate_percent']);

        $this->assertCount(1, $result['per_ticket']);
        $this->assertSame('General A', $result['per_ticket'][0]['ticket']);
        $this->assertSame(2, $result['per_ticket'][0]['attendees']);
        $this->assertSame(1, $result['per_ticket'][0]['checked_in']);

        $this->assertCount(1, $result['recent_check_ins']);
        $this->assertSame('Juan', $result['recent_check_ins'][0]['first_name']);
        $this->assertSame('General A', $result['recent_check_ins'][0]['ticket']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $result['recent_check_ins'][0]['time']);

        $this->assertSame(1, $result['check_in_lists']);
        $this->assertNothingSensitive($result);
    }

    public function test_door_status_on_another_tenants_event_is_event_not_found(): void
    {
        $result = $this->runTool($this->tool(GetDoorStatusTool::class), event_id: $this->theirs->event->id);

        $this->assertSame(['error' => 'event_not_found'], $result);
    }
}
