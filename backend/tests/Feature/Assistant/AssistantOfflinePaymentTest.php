<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use HiEvents\Assistant\Domain\AssistantContext;
use HiEvents\Assistant\Domain\AssistantContextFactory;
use HiEvents\Assistant\Domain\Tools\CreateDraftEventTool;
use HiEvents\Assistant\Domain\Tools\CreateTicketTool;
use HiEvents\Assistant\Domain\Tools\GetEventSetupStatusTool;
use HiEvents\Assistant\Domain\Tools\SetOfflinePaymentTool;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Models\Event;
use HiEvents\Models\EventSetting;
use HiEvents\Repository\Interfaces\AccountUserRepositoryInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolError;
use Tests\TestCase;

class AssistantOfflinePaymentTest extends TestCase
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

    private function draftWithPaidTicket(): int
    {
        $eventId = $this->runTool($this->tool(CreateDraftEventTool::class), title: 'Rock del Puerto', start_date: '2026-11-13 20:00', confirm: true)['event']['id'];
        $this->runTool($this->tool(CreateTicketTool::class), event_id: $eventId, title: 'General', price: 8790, confirm: true);

        return $eventId;
    }

    private function settings(int $eventId): EventSetting
    {
        return EventSetting::where('event_id', $eventId)->first();
    }

    public function test_enabling_needs_instructions_and_a_preview_changes_nothing(): void
    {
        $eventId = $this->draftWithPaidTicket();

        $missing = $this->runTool($this->tool(SetOfflinePaymentTool::class), event_id: $eventId, enabled: true);
        $this->assertSame('instructions_required', $missing['error']);

        $preview = $this->runTool($this->tool(SetOfflinePaymentTool::class), event_id: $eventId, enabled: true, instructions: "Transferí al alias rock.puerto\nMandá el comprobante al 221-555");
        $this->assertSame('needs_confirmation', $preview['status']);
        $this->assertFalse($preview['offline_payment']['currently']);
        $this->assertStringContainsString('rock.puerto', $preview['instructions']);
        $this->assertNotContains('OFFLINE', (array)$this->settings($eventId)->payment_providers);
    }

    public function test_enabling_makes_a_paid_draft_publishable_and_sanitizes_the_text(): void
    {
        $eventId = $this->draftWithPaidTicket();

        $done = $this->runTool(
            $this->tool(SetOfflinePaymentTool::class),
            event_id: $eventId,
            enabled: true,
            instructions: "Alias: rock.puerto <script>alert(1)</script>\nComprobante al 221-555",
            confirm: true,
        );

        $this->assertSame('applied', $done['status']);
        $settings = $this->settings($eventId);
        $this->assertContains('OFFLINE', (array)$settings->payment_providers);
        $this->assertStringNotContainsString('<script', (string)$settings->offline_payment_instructions);
        $this->assertStringContainsString('rock.puerto', (string)$settings->offline_payment_instructions);
        $this->assertStringContainsString('<br', (string)$settings->offline_payment_instructions, 'line breaks survive as HTML');

        $status = $this->runTool($this->tool(GetEventSetupStatusTool::class), event_id: $eventId);
        $payment = collect($status['checklist'])->firstWhere('step', 'payment');
        $this->assertTrue($payment['done'], json_encode($status));
        $this->assertTrue($payment['offline_payment']);
    }

    public function test_disabling_keeps_the_instructions_but_drops_the_provider(): void
    {
        $eventId = $this->draftWithPaidTicket();
        $this->runTool($this->tool(SetOfflinePaymentTool::class), event_id: $eventId, enabled: true, instructions: 'Alias rock.puerto', confirm: true);

        $done = $this->runTool($this->tool(SetOfflinePaymentTool::class), event_id: $eventId, enabled: false, confirm: true);

        $this->assertSame('applied', $done['status']);
        $this->assertNotContains('OFFLINE', (array)$this->settings($eventId)->payment_providers);
    }

    public function test_a_published_event_needs_the_word_modificar(): void
    {
        $eventId = $this->draftWithPaidTicket();
        Event::withoutEvents(fn() => Event::where('id', $eventId)->update(['status' => 'LIVE']));

        $refused = $this->runTool($this->tool(SetOfflinePaymentTool::class), event_id: $eventId, enabled: true, instructions: 'Alias rock.puerto', confirm: true);
        $this->assertSame('confirmation_phrase_required', $refused['error']);

        $done = $this->runTool($this->tool(SetOfflinePaymentTool::class), event_id: $eventId, enabled: true, instructions: 'Alias rock.puerto', confirm: true, confirmation_phrase: 'MODIFICAR');
        $this->assertSame('applied', $done['status']);
    }

    public function test_cannot_touch_another_tenants_event(): void
    {
        $result = $this->runTool($this->tool(SetOfflinePaymentTool::class), event_id: $this->theirs->event->id, enabled: true, instructions: 'x', confirm: true);

        $this->assertSame('event_not_found', $result['error']);
    }
}
