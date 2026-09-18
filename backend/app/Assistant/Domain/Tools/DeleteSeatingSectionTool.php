<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Domain\Tools;

use HiEvents\Assistant\Domain\AssistantContext;
use HiEvents\DomainObjects\SeatingSectionDomainObject;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\SeatingSectionRepositoryInterface;
use HiEvents\Services\Application\Handlers\SeatingSection\DeleteSeatingSectionHandler;
use HiEvents\Services\Domain\Seating\Exception\SeatingSectionInUseException;
use HiEvents\Services\Infrastructure\Authorization\IsAuthorizedService;
use Psr\Log\LoggerInterface;

class DeleteSeatingSectionTool extends AbstractAssistantWriteTool
{
    public function __construct(
        AssistantContext                                   $context,
        IsAuthorizedService                                $isAuthorizedService,
        EventRepositoryInterface                           $events,
        LoggerInterface                                    $logger,
        private readonly SeatingSectionRepositoryInterface $sections,
        private readonly DeleteSeatingSectionHandler       $deleteSection,
    )
    {
        parent::__construct($context, $isAuthorizedService, $events, $logger);
    }

    protected function configure(): void
    {
        $this
            ->as('delete_seating_section')
            ->for('Removes one section from the event seat map. Refused by the platform if any of its seats '
                . 'are held or sold. Irreversible: the organizer must reply with the exact word ELIMINAR. '
                . 'Call without confirm for a preview.')
            ->withNumberParameter('event_id', 'The event.')
            ->withNumberParameter('section_id', 'The section (from get_seating_sections).')
            ->withBooleanParameter('confirm', 'Pass true only after the organizer typed the word.', required: false)
            ->withStringParameter('confirmation_phrase', 'The exact word ELIMINAR typed by the organizer.', required: false);
    }

    public function __invoke(int|float $event_id, int|float $section_id, ?bool $confirm = null, ?string $confirmation_phrase = null): string
    {
        $args = $this->validateArguments(
            compact('event_id', 'section_id'),
            ['event_id' => 'required|integer|min:1', 'section_id' => 'required|integer|min:1'],
        );

        $event = $this->authorizeEvent((int)$args['event_id']);

        /** @var SeatingSectionDomainObject|null $section */
        $section = $this->sections->findFirstWhere(['id' => (int)$args['section_id'], 'event_id' => $event->getId()]);

        if ($section === null) {
            return $this->toJson(['error' => 'section_not_found', 'details' => 'No such section on this event.']);
        }

        $summary = [
            'id' => $section->getId(),
            'name' => $this->clip($section->getName()),
            'rows' => $section->getRowCount(),
            'seats_per_row' => $section->getSeatsPerRow(),
            'total_seats' => $section->getRowCount() * $section->getSeatsPerRow(),
        ];

        if ($confirm !== true) {
            return $this->toJson([
                'status' => 'needs_confirmation',
                'would_delete' => $summary,
                'hint' => 'Deleting is irreversible: describe the section and ask the organizer to reply with the exact word ELIMINAR, then call again with confirm=true and confirmation_phrase.',
            ]);
        }

        if (($refusal = $this->doubleCheck(true, $confirm, $confirmation_phrase, 'ELIMINAR')) !== null) {
            return $refusal;
        }

        try {
            $this->deleteSection->handle($section->getId(), $event->getId());
        } catch (SeatingSectionInUseException $e) {
            return $this->toJson(['error' => 'cannot_delete', 'details' => 'Some seats in this section are held or sold, so the platform keeps it. It can be set inactive from the seating page instead.']);
        }

        $this->logWrite('seating_section_deleted', ['event_id' => $event->getId(), 'section_id' => $section->getId()]);

        return $this->toJson(['status' => 'deleted', 'section' => $summary]);
    }
}
