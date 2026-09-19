<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Actions\CheckInLists;

use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Helper\IdHelper;
use HiEvents\Models\Attendee;
use HiEvents\Models\CheckInList;
use HiEvents\Models\Event;
use HiEvents\Models\EventSetting;
use HiEvents\Models\Order;
use HiEvents\Models\Organizer;
use HiEvents\Models\Product;
use HiEvents\Models\ProductCategory;
use HiEvents\Models\ProductPrice;
use HiEvents\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The door scanner's five public routes, exercised over HTTP the way the phone at the gate does.
 *
 * These exist because the unit tests around check-in mock `CheckInListDataService`, and Mockery does
 * not check that a mocked method exists on the real class: a service method that had been deleted
 * outright still satisfied `shouldReceive()`, so 27 green tests sat on top of a check-in and a
 * check-out that answered 500 to every request. Nothing below mocks anything — each test drives the
 * route, the action, the handler, the domain service, the repository and the database in one go, so
 * the pieces have to actually fit together.
 *
 * Business rules are covered by the unit tests. What is pinned here is the wiring, plus the handful
 * of behaviours that only exist end to end: the unique index that makes a re-sent batch idempotent,
 * the resource that must not leak an email, and the paging cap on an unauthenticated endpoint.
 */
class CheckInPublicEndpointsTest extends TestCase
{
    use DatabaseTransactions;

    private CheckInList $checkInList;
    private Attendee $attendee;
    private Event $event;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->password(Str::random(16))->withAccount()->create();
        $account = $user->accounts()->first();

        // Only to build the scenario: Event::creating stamps the authenticated user onto the row.
        $this->actingAs($user);

        $organizer = Organizer::create([
            'account_id' => $account->id,
            'name' => 'Teatro Passix',
            'email' => 'teatro@test.passix',
            'currency' => 'ARS',
            'timezone' => 'America/Argentina/Buenos_Aires',
        ]);

        $this->event = Event::create([
            'title' => 'Funcion con escaner',
            'account_id' => $account->id,
            'organizer_id' => $organizer->id,
            'status' => 'LIVE',
            'start_date' => now()->addMonth(),
            'end_date' => now()->addMonth()->addHours(3),
            'currency' => 'ARS',
            'timezone' => 'America/Argentina/Buenos_Aires',
            'short_id' => Str::random(8),
        ]);

        // The check-in service reads these settings for every scan, so the row has to exist.
        EventSetting::create(['event_id' => $this->event->id]);

        $category = ProductCategory::create([
            'name' => 'Entradas',
            'event_id' => $this->event->id,
            'order' => 0,
            'is_hidden' => false,
        ]);

        $product = Product::create([
            'title' => 'General',
            'event_id' => $this->event->id,
            'product_category_id' => $category->id,
            'type' => 'PAID',
            'product_type' => 'TICKET',
            'order' => 0,
            'is_hidden' => false,
        ]);

        $productPrice = ProductPrice::create([
            'product_id' => $product->id,
            'price' => 1000,
        ]);

        $this->checkInList = CheckInList::create([
            'short_id' => IdHelper::shortId(IdHelper::CHECK_IN_LIST_PREFIX),
            'name' => 'Puerta principal',
            'event_id' => $this->event->id,
        ]);

        // What makes a ticket belong to this list.
        $this->checkInList->products()->attach($product->id);

        $order = Order::create([
            'short_id' => IdHelper::shortId(IdHelper::ORDER_PREFIX),
            'event_id' => $this->event->id,
            'currency' => 'ARS',
            'status' => OrderStatus::COMPLETED->name,
            'public_id' => IdHelper::publicId('O'),
        ]);

        $this->attendee = Attendee::create([
            'short_id' => IdHelper::shortId(IdHelper::ATTENDEE_PREFIX),
            'email' => 'asistente@test.passix',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_price_id' => $productPrice->id,
            'event_id' => $this->event->id,
            'public_id' => IdHelper::publicId('A'),
            'status' => AttendeeStatus::ACTIVE->name,
        ]);

        // The scanner carries no credentials, so neither do the requests below.
        $this->app->get('auth')->forgetGuards();
    }

    private function listUrl(string $suffix = ''): string
    {
        return '/public/check-in-lists/' . $this->checkInList->short_id . $suffix;
    }

    private function expireTheList(): void
    {
        $this->checkInList->update(['expires_at' => now()->subHour()]);
    }

    private function scan(array $attendees): \Illuminate\Testing\TestResponse
    {
        return $this->postJson($this->listUrl('/check-ins'), ['attendees' => $attendees]);
    }

    public function test_the_list_is_readable_by_anyone_holding_the_link(): void
    {
        $response = $this->getJson($this->listUrl());

        $response->assertOk();
        $this->assertSame('Puerta principal', $response->json('data.name'));
    }

    /**
     * The roster is served to an unauthenticated caller, so the resource withholds the email on
     * purpose. A unit test pins the resource in isolation; this pins what actually goes over the wire.
     */
    public function test_the_roster_lists_the_attendee_without_leaking_their_email(): void
    {
        $response = $this->getJson($this->listUrl('/attendees'));

        $response->assertOk();
        $this->assertSame($this->attendee->public_id, $response->json('data.0.public_id'));
        $this->assertArrayNotHasKey('email', $response->json('data.0'));
    }

    public function test_a_ticket_is_found_by_its_code(): void
    {
        $response = $this->getJson($this->listUrl('/attendees/' . $this->attendee->public_id));

        $response->assertOk();
        $this->assertSame('Ada', $response->json('data.first_name'));
    }

    /**
     * A QR from another event, or from another app entirely, is the ordinary outcome of pointing a
     * scanner at whatever someone holds up. It answered 500 until the handler was allowed to return
     * null.
     */
    public function test_an_unknown_code_answers_404_and_not_500(): void
    {
        $this->getJson($this->listUrl('/attendees/A-NOBODY1'))->assertNotFound();
    }

    /**
     * The scanner re-sends a queued batch until the server answers, so the same check-in arrives
     * more than once as a matter of course. The second one must find the first rather than write a
     * duplicate — that is the partial unique index doing its job.
     */
    public function test_a_repeated_scan_does_not_check_the_same_person_in_twice(): void
    {
        $body = [['public_id' => $this->attendee->public_id, 'action' => 'check-in']];

        $this->scan($body)->assertOk();
        $second = $this->scan($body);

        $second->assertOk();
        $this->assertSame(
            1,
            DB::table('attendee_check_ins')
                ->where('attendee_id', $this->attendee->id)
                ->whereNull('deleted_at')
                ->count(),
            'the retry wrote a second active check-in',
        );
    }

    /**
     * The path that answered 500 for every check-out: the service called a method that no longer
     * existed on the data service, and every unit test around it was mocking that service.
     */
    public function test_a_check_in_can_be_undone(): void
    {
        $this->scan([['public_id' => $this->attendee->public_id, 'action' => 'check-in']])->assertOk();

        $checkInShortId = DB::table('attendee_check_ins')
            ->where('attendee_id', $this->attendee->id)
            ->whereNull('deleted_at')
            ->value('short_id');

        $this->deleteJson($this->listUrl('/check-ins/' . $checkInShortId))->assertSuccessful();

        $this->assertSame(0, DB::table('attendee_check_ins')
            ->where('attendee_id', $this->attendee->id)
            ->whereNull('deleted_at')
            ->count());
    }

    public function test_an_expired_list_takes_no_check_ins(): void
    {
        $this->expireTheList();

        $this->scan([['public_id' => $this->attendee->public_id, 'action' => 'check-in']])
            ->assertStatus(409);
    }

    /**
     * The same window applies to undoing one: with a leaked link this was the way to keep editing
     * the door's record after the event had closed.
     */
    public function test_an_expired_list_takes_no_check_outs(): void
    {
        $this->scan([['public_id' => $this->attendee->public_id, 'action' => 'check-in']])->assertOk();

        $checkInShortId = DB::table('attendee_check_ins')
            ->where('attendee_id', $this->attendee->id)
            ->whereNull('deleted_at')
            ->value('short_id');

        $this->expireTheList();

        $this->deleteJson($this->listUrl('/check-ins/' . $checkInShortId))->assertStatus(409);
    }

    /**
     * The service opens a transaction per attendee, and this endpoint needs no credentials.
     */
    public function test_an_oversized_batch_is_refused(): void
    {
        $attendees = [];
        for ($i = 0; $i < 101; $i++) {
            $attendees[] = ['public_id' => 'A-BULK' . $i, 'action' => 'check-in'];
        }

        $this->scan($attendees)->assertStatus(422)->assertJsonValidationErrors('attendees');
    }

    public function test_the_same_code_cannot_be_repeated_within_one_batch(): void
    {
        $code = $this->attendee->public_id;

        $this->scan([
            ['public_id' => $code, 'action' => 'check-in'],
            ['public_id' => $code, 'action' => 'check-in'],
        ])->assertStatus(422);
    }

    /**
     * A negative per_page used to survive the repository's min() and reach the query builder, which
     * drops a negative limit instead of applying it — answering with the whole roster in one go.
     */
    public function test_a_negative_per_page_cannot_bypass_the_paging_cap(): void
    {
        $response = $this->getJson($this->listUrl('/attendees?per_page=-1'));

        $response->assertOk();
        $this->assertGreaterThan(0, $response->json('meta.per_page'));
    }
}
