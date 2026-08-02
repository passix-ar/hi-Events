<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Auth;

use HiEvents\DomainObjects\Enums\Role;
use HiEvents\Exceptions\UnauthorizedException;
use HiEvents\Repository\Interfaces\UserRepositoryInterface;
use PHPOpenSourceSaver\JWTAuth\JWTAuth;

/**
 * Mints access tokens for an already-authenticated user.
 *
 * Proving who someone is (a password, a Google ID token, an invitation) is deliberately
 * separate from issuing their token, so every authentication method produces identical
 * claims and no new method has to reach for a password it does not have.
 */
readonly class AuthTokenService
{
    public function __construct(
        private JWTAuth                 $jwtAuth,
        private UserRepositoryInterface $userRepository,
    )
    {
    }

    /**
     * Returns null when the user has no account selected yet — the client then prompts
     * them to choose one and asks again with that account id.
     *
     * @throws UnauthorizedException
     */
    public function issueForUser(int $userId, ?int $accountId, ?Role $role): ?string
    {
        if ($accountId === null) {
            return null;
        }

        $subject = $this->userRepository->findAuthenticatableById($userId);

        if ($subject === null) {
            throw new UnauthorizedException(__('User not found'));
        }

        return $this->jwtAuth->claims($this->buildClaims($accountId, $role))->fromUser($subject);
    }

    private function buildClaims(int $accountId, ?Role $role): array
    {
        $claims = ['account_id' => $accountId];

        if ($role !== null) {
            $claims['role'] = $role->value;
        }

        return $claims;
    }
}
