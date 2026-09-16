<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use HiEvents\Assistant\Domain\AssistantContext;
use HiEvents\Assistant\Domain\AssistantContextFactory;
use HiEvents\Assistant\Domain\PanelRoutes;
use HiEvents\Assistant\Domain\Tools\ApplyFlyerPaletteTool;
use HiEvents\Assistant\Domain\Tools\CreateDraftEventTool;
use HiEvents\Assistant\Domain\Tools\GetEventSetupStatusTool;
use HiEvents\Assistant\Domain\Tools\GetPanelRouteTool;
use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Models\EventSetting;
use HiEvents\Models\Image;
use HiEvents\Repository\Interfaces\AccountUserRepositoryInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolError;
use Tests\TestCase;

class AssistantGuideToolsTest extends TestCase
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

    // ── get_panel_route ───────────────────────────────────────────────────

    public function test_every_destination_resolves_to_a_frontend_path(): void
    {
        $eventId = $this->mine->event->id;

        foreach (PanelRoutes::names() as $name) {
            $result = $this->runTool($this->tool(GetPanelRouteTool::class), destination: $name, event_id: $eventId);

            $this->assertArrayHasKey('path', $result, $name);
            $this->assertMatchesRegularExpression('#^/(manage|account)/#', $result['path'], $name);
            $this->assertStringNotContainsString('{', $result['path'], "$name left a placeholder");
            $this->assertSame("[{$result['label']}]({$result['path']})", $result['markdown_link']);
        }
    }

    public function test_the_mercadopago_route_needs_no_event_and_points_at_account_payment(): void
    {
        $result = $this->runTool($this->tool(GetPanelRouteTool::class), destination: 'connect_mercadopago');

        $this->assertSame('/account/payment', $result['path']);
        $this->assertStringContainsString('Conectar MercadoPago', $result['how_to']);
    }

    public function test_an_event_route_for_another_tenants_event_is_not_found(): void
    {
        $result = $this->runTool($this->tool(GetPanelRouteTool::class), destination: 'event_tickets', event_id: $this->theirs->event->id);

        $this->assertSame(['error' => 'event_not_found'], $result);
    }

    public function test_an_event_route_without_an_event_explains_what_is_missing(): void
    {
        $result = $this->runTool($this->tool(GetPanelRouteTool::class), destination: 'publish_event');

        $this->assertSame('invalid_arguments', $result['error']);
    }

    // ── get_event_setup_status ────────────────────────────────────────────

    public function test_setup_status_reports_what_the_event_still_needs(): void
    {
        $draftId = $this->runTool(
            $this->tool(CreateDraftEventTool::class),
            title: 'Sin Nada Todavia', start_date: '2026-12-20 22:00', confirm: true,
        )['event']['id'];

        $result = $this->runTool($this->tool(GetEventSetupStatusTool::class), event_id: $draftId);

        $byStep = array_column($result['checklist'], null, 'step');
        $this->assertFalse($byStep['tickets']['done']);
        $this->assertFalse($byStep['cover_image']['done']);
        $this->assertFalse($byStep['mercadopago']['done']);
        $this->assertFalse($byStep['published']['done']);
        $this->assertFalse($result['ready_to_sell']);
        $this->assertStringContainsString('event_tickets', $result['next_step'], 'the first missing step is tickets');
    }

    public function test_setup_status_of_the_fixture_event_sees_its_ticket(): void
    {
        $result = $this->runTool($this->tool(GetEventSetupStatusTool::class), event_id: $this->mine->event->id);

        $byStep = array_column($result['checklist'], null, 'step');
        $this->assertTrue($byStep['tickets']['done']);
        $this->assertTrue($byStep['published']['done'], 'the fixture event is LIVE');
    }

    public function test_setup_status_of_another_tenants_event_is_not_found(): void
    {
        $result = $this->runTool($this->tool(GetEventSetupStatusTool::class), event_id: $this->theirs->event->id);

        $this->assertSame(['error' => 'event_not_found'], $result);
    }

    // ── apply_flyer_palette ───────────────────────────────────────────────

    public function test_palette_maths_lifts_a_muddy_average_into_a_usable_accent(): void
    {
        $tool = $this->tool(ApplyFlyerPaletteTool::class);

        $palette = $tool->paletteFrom('#6b3a5e'); // dull purple, typical flyer average
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $palette['accent']);
        $this->assertMatchesRegularExpression('/^#[0-9a-f]{6}$/', $palette['background']);
        $this->assertNotSame($palette['accent'], $palette['background']);

        $grey = $tool->paletteFrom('#808080');
        $this->assertSame('#d6ff3d', $grey['accent'], 'a grey flyer keeps the Passix lime');
    }

    public function test_palette_needs_a_cover_and_a_draft(): void
    {
        $draftId = $this->runTool(
            $this->tool(CreateDraftEventTool::class),
            title: 'Sin Portada', start_date: '2026-12-20 22:00', confirm: true,
        )['event']['id'];

        $noCover = $this->runTool($this->tool(ApplyFlyerPaletteTool::class), event_id: $draftId, confirm: true);
        $this->assertSame('no_cover', $noCover['error']);

        $published = $this->runTool($this->tool(ApplyFlyerPaletteTool::class), event_id: $this->mine->event->id, confirm: true);
        $this->assertSame('event_not_draft', $published['error']);
    }

    public function test_palette_is_written_to_the_event_theme_after_confirmation(): void
    {
        $draftId = $this->runTool(
            $this->tool(CreateDraftEventTool::class),
            title: 'Con Portada', start_date: '2026-12-20 22:00', confirm: true,
        )['event']['id'];

        Image::create([
            'account_id' => $this->mine->account->id,
            'entity_id' => $draftId,
            'entity_type' => EventDomainObject::class,
            'type' => ImageType::EVENT_COVER->name,
            'filename' => 'flyer.jpg',
            'disk' => 'public',
            'path' => 'flyer.jpg',
            'size' => 1000,
            'mime_type' => 'image/jpeg',
            'avg_colour' => '#8a2be2',
        ]);

        $preview = $this->runTool($this->tool(ApplyFlyerPaletteTool::class), event_id: $draftId);
        $this->assertSame('needs_confirmation', $preview['status']);
        $this->assertSame('#8a2be2', $preview['would_create']['from_colour']);

        $result = $this->runTool($this->tool(ApplyFlyerPaletteTool::class), event_id: $draftId, confirm: true);
        $this->assertSame('applied', $result['status']);

        $theme = EventSetting::where('event_id', $draftId)->first()->homepage_theme_settings;
        $this->assertSame($result['accent'], $theme['accent']);
        $this->assertSame($result['background'], $theme['background']);
        $this->assertSame('dark', $theme['mode']);
        $this->assertSame('MIRROR_COVER_IMAGE', $theme['background_type']);
    }

    public function test_palette_never_touches_another_tenants_event(): void
    {
        $result = $this->runTool($this->tool(ApplyFlyerPaletteTool::class), event_id: $this->theirs->event->id, confirm: true);

        $this->assertSame(['error' => 'event_not_found'], $result);
    }
}
