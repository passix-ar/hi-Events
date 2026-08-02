<?php

declare(strict_types=1);

namespace HiEvents\Repository\Interfaces;

use HiEvents\DomainObjects\UserDomainObject;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

/**
 * @extends RepositoryInterface<UserDomainObject>
 */
interface UserRepositoryInterface extends RepositoryInterface
{
    /**
     * Returns the user as a JWT subject so tokens can be minted without a password.
     * Domain objects cannot carry JWT identity, and Eloquent belongs in the repository.
     */
    public function findAuthenticatableById(int $userId): ?JWTSubject;

    public function findByIdAndAccountId(int $userId, int $accountId): UserDomainObject;

    public function findUsersByAccountId(int $accountId): ?Collection;

    public function getAllUsersWithAccounts(?string $search, int $perPage): LengthAwarePaginator;
}
