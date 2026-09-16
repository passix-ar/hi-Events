<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use HiEvents\Assistant\Console\EventAlertsCommand;
use HiEvents\Assistant\Domain\Alerts\EventAlertService;
use HiEvents\Assistant\Mail\EventSalesAlertMail;
use HiEvents\Models\Event;
use HiEvents\Models\EventStatistic;
use HiEvents\Models\Product;
use HiEvents\Models\ProductCategory;
use HiEvents\Models\ProductPrice;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Tests\TestCase;

class AssistantEventAlertsTest extends TestCase
{
    use DatabaseTransactions;

    private AssistantFixture $mine;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake((string)config('filesystems.public'));
        Mail::fake();
        // Until the provider registers the namespace (see AssistantServiceProvider), load the views here.
        View::addNamespace('assistant', app_path('Assistant/Resources/views'));
        config([
            'cache.default' => 'array',
            'assistant.alerts.days_ahead' => 14,
            'assistant.alerts.min_sold_pct' => 40,
        ]);

        $this->mine = AssistantFixture::create('A');
        // The fixture event starts in a month, outside the alert window.
        $this->actingAs($this->mine->user, 'api');
    }

    private function service(): EventAlertService
    {
        return $this->app->make(EventAlertService::class);
    }

    private function makeEvent(string $status, int $sold, int $capacity = 100, ?int $daysAhead = 5, string $title = 'Sunset Sessions'): Event
    {
        $start = $daysAhead === null ? now()->subDays(30) : now()->addDays($daysAhead);

        $event = Event::withoutEvents(fn(): Event => Event::create([
            'title' => $title,
            'account_id' => $this->mine->account->id,
            'organizer_id' => $this->mine->organizer->id,
            'user_id' => $this->mine->user->id,
            'status' => $status,
            'start_date' => $start,
            'end_date' => $start->copy()->addHours(3),
            'currency' => 'ARS',
            'timezone' => 'America/Argentina/Buenos_Aires',
            'short_id' => Str::random(8),
        ]));

        $category = ProductCategory::create([
            'name' => 'Entradas',
            'event_id' => $event->id,
            'order' => 0,
            'is_hidden' => false,
        ]);

        $product = Product::create([
            'title' => 'General',
            'event_id' => $event->id,
            'product_category_id' => $category->id,
            'type' => 'PAID',
            'product_type' => 'TICKET',
            'order' => 0,
            'is_hidden' => false,
        ]);

        ProductPrice::create([
            'product_id' => $product->id,
            'price' => 5000.00,
            'initial_quantity_available' => $capacity,
            'quantity_available' => $capacity - $sold,
            'quantity_sold' => $sold,
            'is_hidden' => false,
            'order' => 0,
        ]);

        return $event;
    }

    public function test_live_event_behind_on_sales_is_alerted_and_emailed(): void
    {
        $event = $this->makeEvent('LIVE', sold: 20);

        $alerts = $this->service()->scan();

        $this->assertCount(1, $alerts);
        $this->assertSame($event->id, $alerts[0]['event_id']);
        $this->assertSame(20, $alerts[0]['sold']);
        $this->assertSame(100, $alerts[0]['capacity']);
        $this->assertSame(20.0, $alerts[0]['sold_pct']);
        $this->assertSame(5, $alerts[0]['days_left']);
        $this->assertNull($alerts[0]['benchmark_pct']);
        $this->assertTrue($alerts[0]['sent']);
        $this->assertSame($this->mine->organizer->email, $alerts[0]['organizer_email']);

        $organizerEmail = $this->mine->organizer->email;
        Mail::assertQueued(EventSalesAlertMail::class, static fn(EventSalesAlertMail $m): bool => $m->hasTo($organizerEmail)
            && $m->eventId === $event->id
            && str_contains($m->envelope()->subject, 'Sunset Sessions va al 20% a 5 días del evento'));
    }

    public function test_mail_renders_with_panel_link_and_benchmark(): void
    {
        $mail = new EventSalesAlertMail(
            eventId: 42, title: 'Sunset Sessions', sold: 20, capacity: 100, soldPct: 20.0, daysLeft: 5, benchmarkPct: 75.5,
        );

        $html = $mail->render();

        $this->assertStringContainsString('20 de 100', $html);
        $this->assertStringContainsString('75.5%', $html);
        $this->assertStringContainsString(rtrim((string)config('app.frontend_url'), '/') . '/manage/event/42/dashboard', $html);
    }

    public function test_event_selling_well_is_not_alerted(): void
    {
        $this->makeEvent('LIVE', sold: 80);

        $this->assertSame([], $this->service()->scan());
        Mail::assertNothingOutgoing();
    }

    public function test_draft_event_is_ignored(): void
    {
        $this->makeEvent('DRAFT', sold: 20);

        $this->assertSame([], $this->service()->scan());
        Mail::assertNothingOutgoing();
    }

    public function test_event_outside_window_is_ignored(): void
    {
        $this->makeEvent('LIVE', sold: 20, daysAhead: 20);

        $this->assertSame([], $this->service()->scan());
        Mail::assertNothingOutgoing();
    }

    public function test_uncapped_event_is_skipped(): void
    {
        $event = $this->makeEvent('LIVE', sold: 0);
        ProductPrice::whereIn('product_id', Product::where('event_id', $event->id)->pluck('id'))
            ->update(['initial_quantity_available' => null]);

        $this->assertSame([], $this->service()->scan());
    }

    public function test_second_scan_same_day_does_not_resend(): void
    {
        $this->makeEvent('LIVE', sold: 20);

        $first = $this->service()->scan();
        $second = $this->service()->scan();

        $this->assertTrue($first[0]['sent']);
        $this->assertFalse($second[0]['sent']);
        Mail::assertQueued(EventSalesAlertMail::class, 1);
    }

    public function test_dry_run_lists_without_sending_or_marking(): void
    {
        $this->makeEvent('LIVE', sold: 20);

        $alerts = $this->service()->scan(dryRun: true);

        $this->assertCount(1, $alerts);
        $this->assertFalse($alerts[0]['sent']);
        Mail::assertNothingOutgoing();

        // A dry run must not consume the daily dedupe slot.
        $this->assertTrue($this->service()->scan()[0]['sent']);
        Mail::assertQueued(EventSalesAlertMail::class, 1);
    }

    public function test_benchmark_averages_past_events_of_the_organizer(): void
    {
        $past = $this->makeEvent('LIVE', sold: 90, daysAhead: null, title: 'Pasado');
        EventStatistic::create([
            'event_id' => $past->id,
            'products_sold' => 90,
            'orders_created' => 45,
            'sales_total_gross' => 0,
            'sales_total_before_additions' => 0,
            'total_tax' => 0,
            'total_fee' => 0,
            'total_views' => 0,
            'total_refunded' => 0,
            'attendees_registered' => 90,
        ]);
        $this->makeEvent('LIVE', sold: 20);

        $alerts = $this->service()->scan(dryRun: true);

        $this->assertCount(1, $alerts);
        $this->assertSame(90.0, $alerts[0]['benchmark_pct']);
    }

    public function test_command_dry_run_prints_table(): void
    {
        $this->makeEvent('LIVE', sold: 20);
        Artisan::registerCommand($this->app->make(EventAlertsCommand::class));

        $this->artisan('assistant:event-alerts', ['--dry-run' => true])
            ->expectsOutputToContain('Sunset Sessions')
            ->assertExitCode(0);

        Mail::assertNothingOutgoing();
    }
}
