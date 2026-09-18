<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Domain;

use HiEvents\DomainObjects\EventDomainObject;

/**
 * The events and tickets this conversation has already put a name to.
 *
 * The server keeps no conversation state and the history the client sends back
 * is text only, so without this the model has to guess an id it saw two turns
 * ago - and a guess that lands on another event of the same organizer passes
 * every authorization check. Tools record what they touch here, the reply hands
 * the list to the client, the client sends it back with the history, and the
 * system prompt lists it as "ids already known: never guess".
 *
 * Entries from the client are hints only: every tool still authorizes the id
 * it is given, so a forged entry cannot reach anything the organizer could not.
 */
final class AssistantEntityLedger
{
    public const TYPE_EVENT = 'event';
    public const TYPE_TICKET = 'ticket';
    public const MAX_ENTRIES = 30;
    public const MAX_LABEL_LENGTH = 120;

    /** @var array<string, array{type: string, id: int, label: string}> keyed by "type:id" */
    private array $entries = [];

    /**
     * @param list<array{type: string, id: int, label: string}> $known
     */
    public function __construct(array $known = [])
    {
        foreach ($known as $entry) {
            $this->remember($entry['type'], $entry['id'], $entry['label']);
        }
    }

    public function rememberEvent(EventDomainObject $event): void
    {
        $this->remember(
            self::TYPE_EVENT,
            $event->getId(),
            sprintf(
                '«%s» (%s, empieza %s)',
                $event->getTitle(),
                $event->getStatus(),
                AssistantDates::local($event->getStartDate(), $event->getTimezone() ?? 'UTC') ?? 'sin fecha',
            ),
        );
    }

    public function rememberTicket(int $ticketId, string $title, int $eventId): void
    {
        $this->remember(self::TYPE_TICKET, $ticketId, sprintf('«%s» del event_id %d', $title, $eventId));
    }

    public function remember(string $type, int $id, string $label): void
    {
        if (!in_array($type, [self::TYPE_EVENT, self::TYPE_TICKET], true) || $id < 1) {
            return;
        }

        $key = $type . ':' . $id;
        // Re-inserting moves the entry to the end, so the newest survive the cap.
        unset($this->entries[$key]);
        $this->entries[$key] = [
            'type' => $type,
            'id' => $id,
            'label' => mb_substr(trim(preg_replace('/\s+/', ' ', $label) ?? ''), 0, self::MAX_LABEL_LENGTH),
        ];

        while (count($this->entries) > self::MAX_ENTRIES) {
            array_shift($this->entries);
        }
    }

    /**
     * @return list<array{type: string, id: int, label: string}>
     */
    public function all(): array
    {
        return array_values($this->entries);
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }
}
