<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use HiEvents\Assistant\Domain\AssistantContext;
use HiEvents\Assistant\Domain\AssistantContextFactory;
use HiEvents\Assistant\Domain\Tools\CreateDraftEventTool;
use HiEvents\Assistant\Domain\Tools\CreateTicketTool;
use HiEvents\Assistant\Domain\Tools\PublishEventTool;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Models\AccountMercadopagoPlatform;
use HiEvents\Models\Event;
use HiEvents\Repository\Interfaces\AccountUserRepositoryInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolError;
use Tests\TestCase;

/**
 * publish_event is the one write that makes something public, so these tests
 * are about the gates in front of it: readiness, the explicit PUBLICAR phrase,
 * and never touching another tenant's event.
 */
class AssistantPublishTest extends TestCase
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

        $this->actingAs($this->mine->user, 'api');
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

    private function draftEventId(string $title): int
    {
        $result = $this->runTool(
            $this->tool(CreateDraftEventTool::class),
            title: $title,
            start_date: '2026-12-20 22:00',
            end_date: '2026-12-21 04:00',
            confirm: true,
        );

        return $result['event']['id'];
    }

    private function addTicket(int $eventId, string $title, int $price): void
    {
        $result = $this->runTool(
            $this->tool(CreateTicketTool::class),
            event_id: $eventId,
            title: $title,
            price: $price,
            confirm: true,
        );

        $this->assertSame('created', $result['status']);
    }

    /**
     * The row isSetupCompleteForAccount() looks for: setup_completed_at set and
     * no expired token. Created BEFORE the draft so CreateEventService writes
     * MERCADOPAGO into the event's payment_providers, as it does in production.
     */
    private function connectMercadoPago(): void
    {
        AccountMercadopagoPlatform::create([
            'account_id' => $this->mine->account->id,
            'mp_user_id' => 'mp-' . $this->mine->account->id,
            'setup_completed_at' => now(),
            'token_expires_at' => null,
        ]);
    }

    public function test_a_draft_without_tickets_is_not_ready(): void
    {
        $eventId = $this->draftEventId('Sin Entradas');

        $result = $this->runTool($this->tool(PublishEventTool::class), event_id: $eventId, confirm: true, confirmation_phrase: 'PUBLICAR');

        $this->assertSame('not_ready', $result['error']);
        $this->assertStringContainsString('tickets', $result['missing'][0]);
        $this->assertSame(EventStatus::DRAFT->name, Event::find($eventId)->status);
    }

    public function test_a_paid_draft_without_mercadopago_is_not_ready(): void
    {
        $eventId = $this->draftEventId('Paga Sin MP');
        $this->addTicket($eventId, 'General', 5000);

        $result = $this->runTool($this->tool(PublishEventTool::class), event_id: $eventId, confirm: true, confirmation_phrase: 'PUBLICAR');

        $this->assertSame('not_ready', $result['error']);
        $this->assertStringContainsString('mercadopago', $result['missing'][0]);
        $this->assertSame(EventStatus::DRAFT->name, Event::find($eventId)->status);
    }

    public function test_a_free_draft_passes_the_gate_without_mercadopago(): void
    {
        $eventId = $this->draftEventId('Gratis Sin MP');
        $this->addTicket($eventId, 'Entrada libre', 0);

        $result = $this->runTool($this->tool(PublishEventTool::class), event_id: $eventId);

        $this->assertSame('needs_confirmation', $result['status'], 'a free event does not need MercadoPago to be ready');
        $this->assertSame($eventId, $result['would_create']['event_id']);
        $this->assertSame(EventStatus::DRAFT->name, Event::find($eventId)->status, 'the preview must not publish');
    }

    public function test_confirm_without_the_phrase_does_not_publish(): void
    {
        $this->connectMercadoPago();
        $eventId = $this->draftEventId('Sin Frase');
        $this->addTicket($eventId, 'General', 5000);

        $result = $this->runTool($this->tool(PublishEventTool::class), event_id: $eventId, confirm: true);

        $this->assertSame('confirmation_phrase_required', $result['error']);
        $this->assertSame(EventStatus::DRAFT->name, Event::find($eventId)->status);

        $wrong = $this->runTool($this->tool(PublishEventTool::class), event_id: $eventId, confirm: true, confirmation_phrase: 'publicar');

        $this->assertSame('confirmation_phrase_required', $wrong['error'], 'the phrase is exact, not case-insensitive');
        $this->assertSame(EventStatus::DRAFT->name, Event::find($eventId)->status);
    }

    public function test_confirm_with_the_phrase_publishes_a_ready_draft(): void
    {
        $this->connectMercadoPago();
        $eventId = $this->draftEventId('Lista Para Salir');
        $this->addTicket($eventId, 'General', 5000);

        $result = $this->runTool($this->tool(PublishEventTool::class), event_id: $eventId, confirm: true, confirmation_phrase: 'PUBLICAR');

        $this->assertSame('published', $result['status'], json_encode($result));
        $this->assertSame($eventId, $result['event']['id']);
        $this->assertSame(EventStatus::LIVE->name, $result['event']['status']);
        $this->assertStringContainsString('/event/' . $eventId . '/', $result['public_url']);
        $this->assertSame(EventStatus::LIVE->name, Event::find($eventId)->status);
    }

    public function test_publishing_a_live_event_is_idempotent(): void
    {
        $result = $this->runTool($this->tool(PublishEventTool::class), event_id: $this->mine->event->id, confirm: true, confirmation_phrase: 'PUBLICAR');

        $this->assertSame('already_live', $result['status']);
        $this->assertSame($this->mine->event->id, $result['event']['id']);
    }

    public function test_another_tenants_event_cannot_be_published(): void
    {
        $this->theirs->event->update(['status' => EventStatus::DRAFT->name]);

        $result = $this->runTool($this->tool(PublishEventTool::class), event_id: $this->theirs->event->id, confirm: true, confirmation_phrase: 'PUBLICAR');

        $this->assertSame(['error' => 'event_not_found'], $result);
        $this->assertSame(EventStatus::DRAFT->name, $this->theirs->event->fresh()->status);
    }

    public function test_publishing_enables_mercadopago_on_an_event_created_before_the_account_connected(): void
    {
        // Draft + paid ticket while MP is NOT connected: the event gets no provider.
        $eventId = $this->draftEventId('Creado Antes De MP');
        $this->addTicket($eventId, 'General', 5000);
        $this->assertSame([], \HiEvents\Models\EventSetting::where('event_id', $eventId)->first()->payment_providers ?? []);

        $this->connectMercadoPago();

        $result = $this->runTool($this->tool(PublishEventTool::class), event_id: $eventId, confirm: true, confirmation_phrase: 'PUBLICAR');

        $this->assertSame('published', $result['status']);
        $this->assertSame('LIVE', \HiEvents\Models\Event::find($eventId)->status);
        $this->assertContains('MERCADOPAGO', \HiEvents\Models\EventSetting::where('event_id', $eventId)->first()->payment_providers);
    }
}
