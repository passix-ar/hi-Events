<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Domain;

use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Exceptions\OrganizerNotFoundException;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;

readonly class AssistantContextFactory
{
    public function __construct(
        private OrganizerRepositoryInterface $organizerRepository,
    )
    {
    }

    /**
     * @throws OrganizerNotFoundException
     */
    public function create(UserDomainObject $user, int $accountId, int $organizerId): AssistantContext
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
        );
    }
}
