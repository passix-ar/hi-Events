<?php

declare(strict_types=1);

namespace Tests\Feature\Assistant;

use HiEvents\Assistant\Domain\AssistantContextFactory;
use HiEvents\Assistant\Domain\AssistantSystemPromptBuilder;
use HiEvents\Assistant\Domain\Tools\FindEventsTool;
use HiEvents\Assistant\Domain\Tools\GetEventStatsTool;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Models\Event;
use HiEvents\Repository\Interfaces\AccountUserRepositoryInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\Usage;
use Tests\TestCase;

/**
 * The ids a conversation has resolved travel with the history so the model
 * never has to guess one - a guess that lands on another draft of the same
 * organizer would pass authorization and, with PUBLICAR, publish the wrong event.
 */
class AssistantEntityLedgerTest extends TestCase
{
    use DatabaseTransactions;

    private AssistantFixture $mine;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('assistant.enabled', true);
        config()->set('assistant.writes_enabled', true);
        $this->mine = AssistantFixture::create('A');
    }

    private function token(): string
    {
        $token = $this->postJson('/auth/login', ['email' => $this->mine->user->email, 'password' => $this->mine->password])
            ->headers->get('X-Auth-Token');
        auth()->forgetGuards();

        return (string)$token;
    }

    public function test_tools_record_what_they_touch_and_the_reply_returns_it(): void
    {
        $user = UserDomainObject::hydrateFromModel($this->mine->user);
        $user->setCurrentAccountUser($this->app->make(AccountUserRepositoryInterface::class)->findFirstWhere([
            'user_id' => $this->mine->user->id,
            'account_id' => $this->mine->account->id,
        ]));
        $context = $this->app->make(AssistantContextFactory::class)->create(
            user: $user,
            accountId: $this->mine->account->id,
            organizerId: $this->mine->organizer->id,
        );

        $this->app->make(FindEventsTool::class, ['context' => $context])->handle(period: 'all');
        $this->app->make(GetEventStatsTool::class, ['context' => $context])->handle(event_id: $this->mine->event->id);

        $types = array_column($context->entities->all(), 'type');
        $ids = array_column($context->entities->all(), 'id');

        $this->assertContains('event', $types);
        $this->assertContains('ticket', $types);
        $this->assertContains($this->mine->event->id, $ids);
    }

    public function test_known_entities_come_back_with_the_history_and_reach_the_prompt(): void
    {
        $fake = Prism::fake([
            TextResponseFake::make()->withText('ok')->withUsage(new Usage(promptTokens: 10, completionTokens: 5)),
        ]);

        $response = $this->postJson("/organizers/{$this->mine->organizer->id}/assistant/chat", [
            'messages' => [
                ['role' => 'user', 'content' => 'armá el evento'],
                ['role' => 'assistant', 'content' => 'Listo, creado.', 'entities' => [
                    ['type' => 'event', 'id' => 7917, 'label' => '«Noche Verifica» (DRAFT)'],
                    ['type' => 'ticket', 'id' => 7602, 'label' => '«Early» del event_id 7917'],
                ]],
                ['role' => 'user', 'content' => 'publicalo'],
            ],
        ], ['Authorization' => 'Bearer ' . $this->token()]);

        $response->assertOk()
            ->assertJsonPath('data.entities.0.id', 7917)
            ->assertJsonPath('data.entities.1.id', 7602);

        $fake->assertRequest(function (array $requests): void {
            $prompt = $requests[0]->systemPrompts()[0]->content;
            $this->assertStringContainsString('event_id 7917: «Noche Verifica» (DRAFT)', $prompt);
            $this->assertStringContainsString('ticket_id 7602', $prompt);
        });
    }

    public function test_event_dates_are_reported_in_the_event_timezone(): void
    {
        // 23:00 in Buenos Aires is 02:00 UTC the next day; the model must see the 28th.
        Event::withoutEvents(fn() => Event::where('id', $this->mine->event->id)->update([
            'start_date' => '2026-11-29 02:00:00',
            'end_date' => '2026-11-29 08:00:00',
        ]));

        $user = UserDomainObject::hydrateFromModel($this->mine->user);
        $user->setCurrentAccountUser($this->app->make(AccountUserRepositoryInterface::class)->findFirstWhere([
            'user_id' => $this->mine->user->id,
            'account_id' => $this->mine->account->id,
        ]));
        $context = $this->app->make(AssistantContextFactory::class)->create(
            user: $user,
            accountId: $this->mine->account->id,
            organizerId: $this->mine->organizer->id,
            focusedEventId: $this->mine->event->id,
        );

        $found = json_decode((string)$this->app->make(FindEventsTool::class, ['context' => $context])->handle(period: 'all'), true);
        $stats = json_decode((string)$this->app->make(GetEventStatsTool::class, ['context' => $context])->handle(event_id: $this->mine->event->id), true);
        $event = collect($found['events'])->firstWhere('id', $this->mine->event->id);

        $this->assertSame('2026-11-28 23:00', $event['start_date']);
        $this->assertSame('2026-11-29 05:00', $event['end_date']);
        $this->assertSame('America/Argentina/Buenos_Aires', $event['timezone']);
        $this->assertSame('2026-11-28 23:00', $stats['event']['start_date']);

        $prompt = $this->app->make(AssistantSystemPromptBuilder::class)->build($context);
        $this->assertStringContainsString('empieza 2026-11-28 23:00', $prompt, 'focused event and ledger labels use local time too');
        $this->assertStringNotContainsString('2026-11-29 02:00', $prompt);
    }

    public function test_entities_are_validated(): void
    {
        $headers = ['Authorization' => 'Bearer ' . $this->token()];

        $this->postJson("/organizers/{$this->mine->organizer->id}/assistant/chat", [
            'messages' => [
                ['role' => 'assistant', 'content' => 'x', 'entities' => [['type' => 'order', 'id' => 1, 'label' => 'x']]],
                ['role' => 'user', 'content' => 'hola'],
            ],
        ], $headers)->assertStatus(422)->assertJsonValidationErrors(['messages.0.entities.0.type']);

        $this->postJson("/organizers/{$this->mine->organizer->id}/assistant/chat", [
            'messages' => [
                ['role' => 'assistant', 'content' => 'x', 'entities' => [['type' => 'event', 'id' => 1, 'label' => str_repeat('x', 121)]]],
                ['role' => 'user', 'content' => 'hola'],
            ],
        ], $headers)->assertStatus(422)->assertJsonValidationErrors(['messages.0.entities.0.label']);
    }
}
