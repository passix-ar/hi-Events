<?php

namespace HiEvents\Services\Domain\Auth;

use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\Exceptions\UnauthorizedException;
use HiEvents\Services\Domain\Auth\DTO\LoginResponse;
use PHPOpenSourceSaver\JWTAuth\JWTAuth;

readonly class LoginService
{
    public function __construct(
        private JWTAuth                  $jwtAuth,
        private AuthTokenService         $authTokenService,
        private UserAccountContextService $accountContextService,
    )
    {
    }

    /**
     * @throws UnauthorizedException
     */
    public function authenticate(string $email, string $password, ?int $requestedAccountId): LoginResponse
    {
        $isValidPassword = $this->jwtAuth->attempt([
            'email' => strtolower($email),
            'password' => $password,
        ]);

        if (!$isValidPassword) {
            throw new UnauthorizedException(__('Username or Password are incorrect'));
        }

        /** @var UserDomainObject $user */
        $user = UserDomainObject::hydrateFromModel($this->jwtAuth->user());

        $accountContext = $this->accountContextService->resolve($user->getId(), $requestedAccountId);

        return new LoginResponse(
            accounts: $accountContext->accounts,
            token: $this->authTokenService->issueForUser(
                userId: $user->getId(),
                accountId: $accountContext->accountId,
                role: $accountContext->role,
            ),
            user: $user,
            accountId: $accountContext->accountId,
        );
    }
}
