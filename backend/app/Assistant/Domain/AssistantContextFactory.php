<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Domain;

use HiEvents\Assistant\Domain\Attachments\AssistantAttachmentStore;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Exceptions\OrganizerNotFoundException;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;

readonly class AssistantContextFactory
{
    public function __construct(
        private OrganizerRepositoryInterface $organizerRepository,
        private EventRepositoryInterface     $eventRepository,
        private AssistantAttachmentStore     $attachments,
    )
    {
    }

    /**
     * @throws OrganizerNotFoundException
     */
    public function create(
        UserDomainObject $user,
        int              $accountId,
        int              $organizerId,
        ?int             $focusedEventId = null,
        ?string          $attachmentId = null,
    ): AssistantContext
    {
        /** @var OrganizerDomainObject|null $organizer */
        $organizer = $this->organizerRepository->findFirstWhere([
            'id' => $organizerId,
            'account_id' => $accountId,
        ]);

        if ($organizer === null) {
            throw new OrganizerNotFoundException(__('Organizer not found'));
        }

        return new AssistantContext(
            user: $user,
            accountId: $accountId,
            organizerId: $organizer->getId(),
            organizerName: $organizer->getName(),
            currency: $organizer->getCurrency(),
            timezone: $organizer->getTimezone(),
            focusedEvent: $this->focusedEvent($focusedEventId, $accountId, $organizer->getId()),
            attachment: $attachmentId === null ? null : $this->attachments->find($attachmentId, $accountId),
        );
    }

    /**
     * The frontend says which event is open; it is trusted only after the same
     * tenant predicate the tools use. Anything else is silently dropped: the
     * context is a hint for the model, not a place to learn what exists.
     */
    private function focusedEvent(?int $eventId, int $accountId, int $organizerId): ?EventDomainObject
    {
        if ($eventId === null) {
            return null;
        }

        /** @var EventDomainObject|null $event */
        $event = $this->eventRepository->findFirstWhere([
            'id' => $eventId,
            'account_id' => $accountId,
            'organizer_id' => $organizerId,
        ]);

        return $event;
    }
}
