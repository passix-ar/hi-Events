<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Domain;

use HiEvents\DomainObjects\UserDomainObject;

/**
 * Everything a tool needs to know about who is asking. Built once per request
 * from the authenticated user and the organizer the action already authorized;
 * the model never gets to choose any of these values.
 */
final readonly class AssistantContext
{
    public function __construct(
        public UserDomainObject $user,
        public int              $accountId,
        public int              $organizerId,
        public string           $organizerName,
        public string           $currency,
        public string           $timezone,
    )
    {
    }
}
