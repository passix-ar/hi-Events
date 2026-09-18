<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Domain\Tools;

use HiEvents\Assistant\Domain\AssistantContext;
use HiEvents\Exceptions\CannotDeleteEntityException;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Domain\Event\EventDeletionService;
use HiEvents\Services\Infrastructure\Authorization\IsAuthorizedService;
use Psr\Log\LoggerInterface;

class DeleteEventTool extends AbstractAssistantWriteTool
{
    public const CONFIRMATION_PHRASE = 'ELIMINAR';

    public function __construct(
        AssistantContext                      $context,
        IsAuthorizedService                   $isAuthorizedService,
        EventRepositoryInterface              $events,
        LoggerInterface                       $logger,
        private readonly EventDeletionService $deletion,
    )
    {
        parent::__construct($context, $isAuthorizedService, $events, $logger);
    }

    protected function configure(): void
    {
        $this
            ->as('delete_event')
            ->for('Deletes an event that has no orders. Cannot be undone: always preview, then require the exact word '
                . 'ELIMINAR typed by the organizer as confirmation_phrase. The platform refuses events with orders; '
                . 'then suggest archiving from the panel instead.')
            ->withNumberParameter('event_id', 'The event to delete.')
            ->withBooleanParameter('confirm', 'True only after the organizer confirmed the preview.', required: false)
            ->withStringParameter('confirmation_phrase', 'The exact word the organizer typed (ELIMINAR).', required: false);
    }

    public function __invoke(int|float $event_id, ?bool $confirm = null, ?string $confirmation_phrase = null): string
    {
        $args = $this->validateArguments(['event_id' => $event_id], ['event_id' => 'required|integer|min:1']);
        $event = $this->authorizeEvent((int)$args['event_id']);

        if (!$this->deletion->canDeleteEvent($event->getId())) {
            return $this->toJson([
                'error' => 'cannot_delete',
                'details' => 'This event has orders, so the platform does not delete it. It can be archived from the panel: the status control on the event dashboard (get_panel_route publish_event).',
            ]);
        }

        $payload = [
            'event' => ['id' => $event->getId(), 'title' => $this->clip($event->getTitle()), 'status' => $event->getStatus()],
            'irreversible' => true,
            'double_check' => 'Ask the organizer to reply with the exact word ' . self::CONFIRMATION_PHRASE . '.',
        ];

        if ($confirm !== true) {
            return $this->preview($payload);
        }

        if (($blocked = $this->doubleCheck(true, $confirm, $confirmation_phrase, self::CONFIRMATION_PHRASE)) !== null) {
            return $blocked;
        }

        try {
            $this->deletion->deleteEvent($event->getId(), $this->context->accountId);
        } catch (CannotDeleteEntityException $e) {
            return $this->toJson(['error' => 'cannot_delete', 'details' => $e->getMessage()]);
        }

        $this->logWrite('event_deleted', ['event_id' => $event->getId(), 'title' => $event->getTitle()]);

        return $this->toJson(['status' => 'deleted', 'event' => $payload['event']]);
    }
}
