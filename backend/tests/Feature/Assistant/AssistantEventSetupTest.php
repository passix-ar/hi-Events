<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use HiEvents\Assistant\Domain\AssistantContext;
use HiEvents\Assistant\Domain\AssistantContextFactory;
use HiEvents\Assistant\Domain\Tools\CreateDraftEventTool;
use HiEvents\Assistant\Domain\Tools\CreateTicketTool;
use HiEvents\Assistant\Domain\Tools\SetCheckoutSettingsTool;
use HiEvents\Assistant\Domain\Tools\SetEventLocationTool;
use HiEvents\Assistant\Domain\Tools\SetPlatformFeePayerTool;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Models\Event;
use HiEvents\Models\EventSetting;
use HiEvents\Repository\Interfaces\AccountUserRepositoryInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolError;
use Tests\TestCase;

/**
 * The "make it sellable" settings: who pays the commission, checkout basics,
 * a real address. All through PartialUpdateEventSettingsHandler like the panel.
 */
class AssistantEventSetupTest extends TestCase
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

    private function draft(): int
    {
        $eventId = $this->runTool($this->tool(CreateDraftEventTool::class), title: 'Rock del Puerto', start_date: '2026-11-13 20:00', venue_name: 'Galpón 9', city: 'La Plata', confirm: true)['event']['id'];
        $this->runTool($this->tool(CreateTicketTool::class), event_id: $eventId, title: 'General', price: 8790, confirm: true);

        return $eventId;
    }

    private function settings(int $eventId): EventSetting
    {
        return EventSetting::where('event_id', $eventId)->first();
    }

    // ── commission ────────────────────────────────────────────────────────

    public function test_commission_preview_shows_both_options_with_real_amounts(): void
    {
        config()->set('app.saas_mode_enabled', true);
        $eventId = $this->draft();

        $preview = $this->runTool($this->tool(SetPlatformFeePayerTool::class), event_id: $eventId, payer: 'organizer');

        $this->assertSame('needs_confirmation', $preview['status']);
        $this->assertSame(8790.0, $preview['example']['sample_ticket_price'], 'the cheapest paid ticket is the sample');
        $this->assertArrayHasKey('if_buyer_pays', $preview['example']);
        $this->assertArrayHasKey('if_organizer_pays', $preview['example']);
        $this->assertGreaterThanOrEqual($preview['example']['sample_ticket_price'], $preview['example']['if_buyer_pays']['buyer_pays']);
    }

    public function test_commission_payer_is_applied_and_needs_modificar_when_live(): void
    {
        $eventId = $this->draft();

        $done = $this->runTool($this->tool(SetPlatformFeePayerTool::class), event_id: $eventId, payer: 'organizer', confirm: true);
        $this->assertSame('applied', $done['status']);
        $this->assertFalse((bool)$this->settings($eventId)->pass_platform_fee_to_buyer);

        Event::withoutEvents(fn() => Event::where('id', $eventId)->update(['status' => 'LIVE']));
        $refused = $this->runTool($this->tool(SetPlatformFeePayerTool::class), event_id: $eventId, payer: 'buyer', confirm: true);
        $this->assertSame('confirmation_phrase_required', $refused['error']);
        $this->assertFalse((bool)$this->settings($eventId)->pass_platform_fee_to_buyer);

        $ok = $this->runTool($this->tool(SetPlatformFeePayerTool::class), event_id: $eventId, payer: 'buyer', confirm: true, confirmation_phrase: 'MODIFICAR');
        $this->assertSame('applied', $ok['status']);
        $this->assertTrue((bool)$this->settings($eventId)->pass_platform_fee_to_buyer);
    }

    // ── checkout ──────────────────────────────────────────────────────────

    public function test_checkout_settings_change_only_what_is_given_and_sanitize_text(): void
    {
        $eventId = $this->draft();
        $before = $this->settings($eventId);

        $preview = $this->runTool($this->tool(SetCheckoutSettingsTool::class), event_id: $eventId, support_email: 'hola@rock.com', terms: "Sin reembolsos <script>x</script>\nSalvo suspensión");
        $this->assertSame('needs_confirmation', $preview['status']);
        $this->assertSame(['support_email', 'terms'], array_keys($preview['changes']));
        $this->assertSame($before->support_email, $this->settings($eventId)->support_email);

        $done = $this->runTool($this->tool(SetCheckoutSettingsTool::class), event_id: $eventId, support_email: 'hola@rock.com', terms: "Sin reembolsos <script>x</script>\nSalvo suspensión", attendee_details: 'per_order', reservation_minutes: 20, confirm: true);
        $this->assertSame('applied', $done['status']);

        $after = $this->settings($eventId);
        $this->assertSame('hola@rock.com', $after->support_email);
        $this->assertStringNotContainsString('<script', (string)$after->pre_checkout_message);
        $this->assertStringContainsString('Sin reembolsos', (string)$after->pre_checkout_message);
        $this->assertSame('PER_ORDER', $after->attendee_details_collection_method);
        $this->assertSame(20, $after->order_timeout_in_minutes);
        $this->assertSame($before->post_checkout_message, $after->post_checkout_message, 'untouched fields stay');

        $nothing = $this->runTool($this->tool(SetCheckoutSettingsTool::class), event_id: $eventId);
        $this->assertSame('nothing_to_change', $nothing['status']);
        $this->assertSame('hola@rock.com', $nothing['current']['support_email']);
    }

    // ── location ──────────────────────────────────────────────────────────

    public function test_location_needs_a_real_address_and_keeps_the_venue(): void
    {
        $eventId = $this->draft();

        $incomplete = $this->runTool($this->tool(SetEventLocationTool::class), event_id: $eventId, city: 'La Plata', confirm: true);
        $this->assertSame('address_incomplete', $incomplete['error']);

        $done = $this->runTool($this->tool(SetEventLocationTool::class), event_id: $eventId, address_line_1: 'Calle 9 1234', city: 'La Plata', postcode: '1900', state_or_region: 'Buenos Aires', maps_url: 'https://maps.google.com/?q=x', confirm: true);
        $this->assertSame('applied', $done['status']);

        $location = (array)$this->settings($eventId)->location_details;
        $this->assertSame('Galpón 9', $location['venue_name'], 'venue from creation survives');
        $this->assertSame('Calle 9 1234', $location['address_line_1']);
        $this->assertSame('1900', $location['zip_or_postal_code']);
        $this->assertSame('AR', $location['country']);
        $this->assertFalse((bool)$this->settings($eventId)->is_online_event);
    }

    public function test_online_event_drops_the_address_and_keeps_access_details(): void
    {
        $eventId = $this->draft();

        $done = $this->runTool($this->tool(SetEventLocationTool::class), event_id: $eventId, online: true, access_details: "Zoom: https://zoom.us/j/123\nClave 4567", confirm: true);
        $this->assertSame('applied', $done['status']);

        $settings = $this->settings($eventId);
        $this->assertTrue((bool)$settings->is_online_event);
        $this->assertStringContainsString('zoom.us', (string)$settings->online_event_connection_details);
    }

    public function test_cannot_touch_another_tenants_event(): void
    {
        foreach ([
            fn() => $this->runTool($this->tool(SetPlatformFeePayerTool::class), event_id: $this->theirs->event->id, payer: 'buyer', confirm: true),
            fn() => $this->runTool($this->tool(SetCheckoutSettingsTool::class), event_id: $this->theirs->event->id, support_email: 'x@y.com', confirm: true),
            fn() => $this->runTool($this->tool(SetEventLocationTool::class), event_id: $this->theirs->event->id, online: true, confirm: true),
        ] as $attempt) {
            $this->assertSame('event_not_found', $attempt()['error']);
        }
    }
}
