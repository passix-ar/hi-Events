<?php

declare(strict_types=1);

namespace Tests\Unit\Assistant;

use HiEvents\Assistant\Domain\AssistantEntityLedger;
use HiEvents\Assistant\Domain\AssistantSystemPromptBuilder;
use HiEvents\Assistant\Domain\AssistantContext;
use Tests\TestCase;

class AssistantEntityLedgerTest extends TestCase
{
    use AssistantTestHelpers;

    public function test_remembers_events_and_tickets_without_duplicates(): void
    {
        $ledger = new AssistantEntityLedger();

        $ledger->rememberEvent($this->makeEvent(7, 1, 10, 'Noche Verifica'));
        $ledger->rememberTicket(70, 'Early', 7);
        $ledger->rememberEvent($this->makeEvent(7, 1, 10, 'Noche Verifica (renombrada)'));

        $this->assertCount(2, $ledger->all());
        $this->assertSame('ticket', $ledger->all()[0]['type']);
        $this->assertSame(7, $ledger->all()[1]['id']);
        $this->assertStringContainsString('renombrada', $ledger->all()[1]['label'], 'the latest label wins');
    }

    public function test_seeds_from_client_entries_but_drops_garbage(): void
    {
        $ledger = new AssistantEntityLedger([
            ['type' => 'event', 'id' => 3, 'label' => "  «Fiesta»\n\n(LIVE) "],
            ['type' => 'order', 'id' => 4, 'label' => 'not a supported type'],
            ['type' => 'ticket', 'id' => 0, 'label' => 'bad id'],
            ['type' => 'ticket', 'id' => 9, 'label' => str_repeat('x', 500)],
        ]);

        $entries = $ledger->all();

        $this->assertCount(2, $entries);
        $this->assertSame('«Fiesta» (LIVE)', $entries[0]['label']);
        $this->assertSame(AssistantEntityLedger::MAX_LABEL_LENGTH, mb_strlen($entries[1]['label']));
    }

    public function test_keeps_only_the_newest_entries(): void
    {
        $ledger = new AssistantEntityLedger();

        foreach (range(1, AssistantEntityLedger::MAX_ENTRIES + 5) as $id) {
            $ledger->rememberTicket($id, 'T' . $id, 1);
        }

        $this->assertCount(AssistantEntityLedger::MAX_ENTRIES, $ledger->all());
        $this->assertSame(6, $ledger->all()[0]['id']);
    }

    public function test_known_entities_are_listed_in_the_prompt_and_guessing_is_forbidden(): void
    {
        $context = $this->makeContext();
        $context->entities->rememberEvent($this->makeEvent(7917, 1, 10, 'Noche Verifica'));
        $context->entities->rememberTicket(7602, 'Early', 7917);

        $prompt = (new AssistantSystemPromptBuilder())->build($context);

        $this->assertStringContainsString('Nunca adivines ni inventes un event_id', $prompt);
        $this->assertStringContainsString('Ya identificados en esta conversación (usá estos ids directamente)', $prompt);
        $this->assertStringContainsString('event_id 7917: «Noche Verifica»', $prompt);
        $this->assertStringContainsString('ticket_id 7602: «Early» del event_id 7917', $prompt);

        $empty = (new AssistantSystemPromptBuilder())->build($this->makeContext());
        $this->assertStringNotContainsString('usá estos ids directamente', $empty);
        $this->assertInstanceOf(AssistantContext::class, $context);
    }
}
