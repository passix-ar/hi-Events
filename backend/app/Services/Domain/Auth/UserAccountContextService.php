<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Auth;

use HiEvents\DomainObjects\AccountDomainObject;
use HiEvents\DomainObjects\AccountUserDomainObject;
use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\Status\UserStatus;
use HiEvents\Exceptions\UnauthorizedException;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\AccountUserRepositoryInterface;
use HiEvents\Services\Domain\Auth\DTO\AccountContextDTO;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;

/**
 * Resolves which account a user is signing in to and enforces that the membership is
 * active. Shared by every authentication method so that access rules cannot drift apart
 * between, say, password login and Google login.
 */
readonly class UserAccountContextService
{
    public function __construct(
        private AccountUserRepositoryInterface $accountUserRepository,
        private LoggerInterface                $logger,
    )
    {
    }

    /**
     * @throws UnauthorizedException
     */
    public function resolve(int $userId, ?int $requestedAccountId): AccountContextDTO
    {
        $userAccounts = $this->accountUserRepository
            ->loadRelation(new Relationship(domainObject: AccountDomainObject::class, name: 'account'))
            ->findWhere([
                'user_id' => $userId,
            ]);

        $accounts = $userAccounts->map(fn(AccountUserDomainObject $accountUser) => $accountUser->getAccount());

        $accountId = $this->getAccountId($accounts, $requestedAccountId);

        if ($accountId) {
            $this->validateUserStatus($accountId, $userAccounts);
        }

        return new AccountContextDTO(
            accounts: $accounts,
            accountId: $accountId,
            role: $this->getUserRole($accountId, $userAccounts),
        );
    }

    public function recordLogin(int $userId, ?int $accountId): void
    {
        if ($accountId === null) {
            return;
        }

        $this->accountUserRepository->updateWhere(
            attributes: ['last_login_at' => now()],
            where: ['user_id' => $userId, 'account_id' => $accountId],
        );
    }

    /**
     * @throws UnauthorizedException
     */
    private function getAccountId(Collection $accounts, ?int $requestedAccountId): ?int
    {
        if ($accounts->count() === 1) {
            return $accounts->first()->getId();
        }

        if ($requestedAccountId) {
            $verifiedAccount = $accounts->firstWhere(
                fn(AccountDomainObject $account) => $account->getId() === $requestedAccountId
            );

            if ($verifiedAccount === null) {
                throw new UnauthorizedException(__('Account not found'));
            }

            return $verifiedAccount->getId();
        }

        return null;
    }

    /**
     * @throws UnauthorizedException
     */
    private function validateUserStatus(int $accountId, Collection $userAccounts): void
    {
        /** @var AccountUserDomainObject $currentAccount */
        $currentAccount = $userAccounts
            ->first(fn(AccountUserDomainObject $userAccount) => $userAccount->getAccountId() === $accountId);

        if ($currentAccount->getStatus() !== UserStatus::ACTIVE->name) {
            $this->logger->info(__('Attempt to log in to a non-active account'), $currentAccount->toArray());

            throw new UnauthorizedException(__('User account is not active'));
        }
    }

    private function getUserRole(?int $accountId, Collection $userAccounts): ?Role
    {
        if ($accountId === null) {
            return null;
        }

        /** @var AccountUserDomainObject $currentAccount */
        $currentAccount = $userAccounts
            ->first(fn(AccountUserDomainObject $userAccount) => $userAccount->getAccountId() === $accountId);

        return Role::from($currentAccount?->getRole());
    }
}
