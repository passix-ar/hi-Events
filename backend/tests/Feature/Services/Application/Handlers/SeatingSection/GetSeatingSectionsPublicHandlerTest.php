<?php

declare(strict_types=1);

namespace Tests\Feature\Services\Application\Handlers\SeatingSection;

use HiEvents\DomainObjects\Status\SeatingSectionStatus;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Models\Event;
use HiEvents\Models\Organizer;
use HiEvents\Models\Product;
use HiEvents\Models\ProductCategory;
use HiEvents\Models\Seat;
use HiEvents\Models\SeatingSection;
use HiEvents\Models\User;
use HiEvents\Services\Application\Handlers\SeatingSection\DTO\GetSeatingSectionsPublicDTO;
use HiEvents\Services\Application\Handlers\SeatingSection\GetSeatingSectionsPublicHandler;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The seat map is a public endpoint, so it must not answer for an event that has not been
 * published — the layout and how it is selling would leak before the announcement. The
 * organizer previewing their own draft still has to see it.
 */
class GetSeatingSectionsPublicHandlerTest extends TestCase
{
    use DatabaseTransactions;

    private GetSeatingSectionsPublicHandler $handler;

    private Event $event;

    private int $accountId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->handler = $this->app->make(GetSeatingSectionsPublicHandler::class);

        $user = User::factory()->password(Str::random(16))->withAccount()->create();
        $this->actingAs($user);
        $account = $user->accounts()->first();
        $this->accountId = $account->id;

        $organizer = Organizer::create([
            'account_id' => $account->id,
            'name' => 'Teatro Passix',
            'email' => 'teatro@test.passix',
            'currency' => 'ARS',
            'timezone' => 'America/Argentina/Buenos_Aires',
        ]);

        $this->event = Event::create([
            'title' => 'Funcion con butacas',
            'account_id' => $account->id,
            'organizer_id' => $organizer->id,
            'status' => 'LIVE',
            'start_date' => now()->addMonth(),
            'end_date' => now()->addMonth()->addHours(3),
            'currency' => 'ARS',
            'timezone' => 'America/Argentina/Buenos_Aires',
            'short_id' => Str::random(8),
        ]);

        $category = ProductCategory::create([
            'name' => 'Entradas',
            'event_id' => $this->event->id,
            'order' => 0,
            'is_hidden' => false,
        ]);

        $product = Product::create([
            'title' => 'Platea',
            'event_id' => $this->event->id,
            'product_category_id' => $category->id,
            'type' => 'PAID',
            'product_type' => 'TICKET',
            'order' => 0,
            'is_hidden' => false,
        ]);

        $section = SeatingSection::create([
            'event_id' => $this->event->id,
            'product_id' => $product->id,
            'name' => 'Platea baja',
            'row_count' => 1,
            'seats_per_row' => 1,
            'status' => SeatingSectionStatus::ACTIVE->name,
        ]);

        Seat::create([
            'event_id' => $this->event->id,
            'seating_section_id' => $section->id,
            'row_label' => 'A',
            'seat_number' => 1,
            'label' => 'A1',
        ]);
    }

    public function test_a_live_event_is_visible_to_anyone(): void
    {
        $plan = $this->handler->handle($this->dto());

        $this->assertCount(1, $plan->sections);
        $this->assertCount(1, $plan->sections->first()->getSeats());
    }

    public function test_an_event_with_no_saved_layout_still_gets_a_stage(): void
    {
        $plan = $this->handler->handle($this->dto());

        $this->assertSame(0, $plan->stage_x);
        $this->assertSame(-140, $plan->stage_y);
    }

    public function test_an_unpublished_event_is_hidden_from_the_public(): void
    {
        $this->event->update(['status' => 'DRAFT']);

        $this->expectException(ResourceNotFoundException::class);

        $this->handler->handle($this->dto());
    }

    public function test_the_owning_account_can_still_preview_its_draft(): void
    {
        $this->event->update(['status' => 'DRAFT']);

        $plan = $this->handler->handle($this->dto(accountId: $this->accountId));

        $this->assertCount(1, $plan->sections);
    }

    public function test_another_account_cannot_see_the_draft(): void
    {
        $this->event->update(['status' => 'DRAFT']);

        $this->expectException(ResourceNotFoundException::class);

        $this->handler->handle($this->dto(accountId: $this->accountId + 1000));
    }

    public function test_a_superadmin_can_see_the_draft(): void
    {
        $this->event->update(['status' => 'DRAFT']);

        $plan = $this->handler->handle($this->dto(isSuperAdmin: true));

        $this->assertCount(1, $plan->sections);
    }

    private function dto(?int $accountId = null, bool $isSuperAdmin = false): GetSeatingSectionsPublicDTO
    {
        return GetSeatingSectionsPublicDTO::from([
            'event_id' => $this->event->id,
            'authenticated_account_id' => $accountId,
            'is_super_admin' => $isSuperAdmin,
        ]);
    }
}
