<?php

namespace HiEvents\Services\Application\Handlers\Auth;

use HiEvents\Services\Application\Handlers\Auth\DTO\LoginCredentialsDTO;
use HiEvents\Services\Domain\Auth\DTO\LoginResponse;
use HiEvents\Services\Domain\Auth\LoginService;
use HiEvents\Services\Domain\Auth\UserAccountContextService;

readonly class LoginHandler
{
    public function __construct(
        private LoginService              $loginService,
        private UserAccountContextService $accountContextService,
    )
    {
    }

    public function handle(LoginCredentialsDTO $loginCredentials): LoginResponse
    {
        $loginResponse = $this->loginService->authenticate(
            email: $loginCredentials->email,
            password: $loginCredentials->password,
            requestedAccountId: $loginCredentials->accountId,
        );

        $this->accountContextService->recordLogin(
            userId: $loginResponse->user->getId(),
            accountId: $loginResponse->accountId,
        );

        return $loginResponse;
    }
}
