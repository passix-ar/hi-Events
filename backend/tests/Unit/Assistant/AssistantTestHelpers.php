<?php

declare(strict_types=1);

namespace Tests\Unit\Assistant;

use HiEvents\Assistant\Domain\AssistantContext;
use HiEvents\DomainObjects\AccountUserDomainObject;
use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Status\UserStatus;
use HiEvents\DomainObjects\UserDomainObject;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\ToolError;

trait AssistantTestHelpers
{
    /**
     * Runs the tool the way Prism does and returns what the model would see.
     */
    protected function runTool(Tool $tool, mixed ...$arguments): string
    {
        $output = $tool->handle(...$arguments);

        return $output instanceof ToolError ? $output->message : (string)$output;
    }

    protected function makeUser(int $userId, int $accountId, string $role = Role::ORGANIZER->name): UserDomainObject
    {
        $accountUser = (new AccountUserDomainObject())
            ->setId($userId * 100)
            ->setUserId($userId)
            ->setAccountId($accountId)
            ->setRole($role)
            ->setStatus(UserStatus::ACTIVE->name);

        return (new UserDomainObject())
            ->setId($userId)
            ->setCurrentAccountUser($accountUser);
    }

    protected function makeContext(int $accountId = 1, int $organizerId = 10, int $userId = 5): AssistantContext
    {
        return new AssistantContext(
            user: $this->makeUser($userId, $accountId),
            accountId: $accountId,
            organizerId: $organizerId,
            organizerName: 'Passix Test Org',
            currency: 'ARS',
            timezone: 'America/Argentina/Buenos_Aires',
        );
    }

    protected function makeEvent(int $id, int $accountId, int $organizerId, string $title = 'Fiesta'): EventDomainObject
    {
        return (new EventDomainObject())
            ->setId($id)
            ->setAccountId($accountId)
            ->setOrganizerId($organizerId)
            ->setTitle($title)
            ->setStatus('LIVE')
            ->setCurrency('ARS')
            ->setTimezone('America/Argentina/Buenos_Aires')
            ->setCreatedAt('2026-01-01 00:00:00');
    }
}
