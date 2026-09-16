<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use HiEvents\Assistant\Domain\AssistantContext;
use HiEvents\Assistant\Domain\AssistantContextFactory;
use HiEvents\Assistant\Domain\Tools\CreateDraftEventTool;
use HiEvents\Assistant\Domain\Tools\GetEventPromoKitTool;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Repository\Interfaces\AccountUserRepositoryInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolError;
use Tests\TestCase;

class AssistantPromoKitTest extends TestCase
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

    public function test_happy_path_returns_facts_and_the_public_link(): void
    {
        // 2026-12-20 23:00 UTC is 20:00 in Buenos Aires (UTC-3).
        $this->mine->event->update([
            'start_date' => '2026-12-20 23:00:00',
            'end_date' => '2026-12-21 02:00:00',
            'description' => '<p>Una <strong>fiesta</strong> &amp; algo m&aacute;s</p>',
            'location_details' => ['venue_name' => 'Club Sur', 'city' => 'Rosario', 'address_line_1' => 'Calle 1'],
        ]);

        $result = $this->runTool($this->tool(GetEventPromoKitTool::class), event_id: $this->mine->event->id);

        $this->assertSame('Evento A', $result['event']['title']);
        $this->assertSame('LIVE', $result['event']['status']);
        $this->assertTrue($result['event']['is_published']);
        $this->assertSame('2026-12-20 20:00', $result['event']['start_date'], 'start date in the organizer timezone');
        $this->assertSame('2026-12-20 23:00', $result['event']['end_date']);
        $this->assertSame('America/Argentina/Buenos_Aires', $result['event']['timezone']);
        $this->assertSame('Una fiesta & algo más', $result['event']['description']);
        $this->assertSame('Club Sur', $result['event']['location']['venue_name']);
        $this->assertSame('Rosario', $result['event']['location']['city']);
        $this->assertSame('Calle 1', $result['event']['location']['address']);
        $this->assertSame('ARS', $result['event']['currency']);
        $this->assertSame('Org A', $result['organizer_name']);

        $this->assertCount(1, $result['tickets']);
        $this->assertSame('General A', $result['tickets'][0]['title']);
        $this->assertSame(5000.0, $result['tickets'][0]['price']);
        $this->assertFalse($result['tickets'][0]['is_free']);
        $this->assertSame(98, $result['tickets'][0]['available']);
        $this->assertFalse($result['tickets'][0]['sold_out']);

        $frontendUrl = (string)config('app.frontend_url');
        $this->assertStringStartsWith($frontendUrl, $result['public_url']);
        $this->assertStringContainsString('/event/' . $this->mine->event->id . '/', $result['public_url']);
        $this->assertStringStartsWith($frontendUrl, $result['organizer_public_url']);
        $this->assertStringContainsString('/events/' . $this->mine->organizer->id . '/', $result['organizer_public_url']);

        $this->assertArrayNotHasKey('note', $result);

        $raw = json_encode($result);
        $this->assertStringNotContainsString('buyer-a@test.passix', $raw, 'no buyer data');
        $this->assertStringNotContainsString('SECRET NOTE', $raw, 'no buyer data');
    }

    public function test_description_is_clipped(): void
    {
        $this->mine->event->update(['description' => '<p>' . str_repeat('palabra ', 200) . '</p>']);

        $result = $this->runTool($this->tool(GetEventPromoKitTool::class), event_id: $this->mine->event->id);

        $this->assertLessThanOrEqual(601, mb_strlen($result['event']['description']));
        $this->assertStringEndsWith('…', $result['event']['description']);
    }

    public function test_draft_event_still_returns_the_url_with_a_note(): void
    {
        $draftId = $this->runTool(
            $this->tool(CreateDraftEventTool::class),
            title: 'Borrador Promo', start_date: '2026-12-20 22:00', confirm: true,
        )['event']['id'];

        $result = $this->runTool($this->tool(GetEventPromoKitTool::class), event_id: $draftId);

        $this->assertSame('DRAFT', $result['event']['status']);
        $this->assertFalse($result['event']['is_published']);
        $this->assertSame('the page is not public until the event is published', $result['note']);
        $this->assertStringContainsString('/event/' . $draftId . '/', $result['public_url']);
        $this->assertSame('2026-12-20 22:00', $result['event']['start_date'], 'round-trips through UTC back to local');
        $this->assertSame([], $result['tickets']);
    }

    public function test_cross_tenant_event_is_not_found(): void
    {
        $result = $this->runTool($this->tool(GetEventPromoKitTool::class), event_id: $this->theirs->event->id);

        $this->assertSame(['error' => 'event_not_found'], $result);
    }

    public function test_invalid_event_id_is_rejected(): void
    {
        $result = $this->runTool($this->tool(GetEventPromoKitTool::class), event_id: 0);

        $this->assertSame('invalid_arguments', $result['error']);
    }
}
