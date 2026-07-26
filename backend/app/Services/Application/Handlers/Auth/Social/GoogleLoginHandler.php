<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Auth\Social;

use HiEvents\Exceptions\SocialAuth\InvalidIdTokenException;
use HiEvents\Exceptions\SocialAuth\SocialAuthDisabledException;
use HiEvents\Exceptions\SocialAuth\SocialIdentityAlreadyLinkedException;
use HiEvents\Exceptions\UnauthorizedException;
use HiEvents\Services\Application\Handlers\Auth\Social\DTO\GoogleLoginDTO;
use HiEvents\Services\Application\Handlers\Auth\Social\DTO\SocialAuthResultDTO;
use HiEvents\Services\Domain\Auth\AuthTokenService;
use HiEvents\Services\Domain\Auth\DTO\LoginResponse;
use HiEvents\Services\Domain\Auth\SocialAuthNonceService;
use HiEvents\Services\Domain\Auth\SocialIdentityService;
use HiEvents\Services\Domain\Auth\SocialRegistrationTokenService;
use HiEvents\Services\Domain\Auth\UserAccountContextService;
use HiEvents\Services\Infrastructure\SocialAuth\Google\GoogleIdTokenVerifier;
use Illuminate\Database\DatabaseManager;
use Throwable;

readonly class GoogleLoginHandler
{
    public function __construct(
        private GoogleIdTokenVerifier          $idTokenVerifier,
        private SocialIdentityService          $socialIdentityService,
        private SocialRegistrationTokenService $registrationTokenService,
        private SocialAuthNonceService         $nonceService,
        private UserAccountContextService      $accountContextService,
        private AuthTokenService               $authTokenService,
        private DatabaseManager                $databaseManager,
    )
    {
    }

    /**
     * @throws SocialAuthDisabledException
     * @throws InvalidIdTokenException
     * @throws SocialIdentityAlreadyLinkedException
     * @throws UnauthorizedException
     * @throws Throwable
     */
    public function handle(GoogleLoginDTO $loginData): SocialAuthResultDTO
    {
        $profile = $this->idTokenVerifier->verify($loginData->idToken);

        // Single-use: a token presented a second time finds its nonce already spent, so
        // capturing one in transit buys an attacker nothing.
        if (!$this->nonceService->consume($profile->nonce)) {
            throw new InvalidIdTokenException(
                __('Your sign in attempt has expired. Please try again.')
            );
        }

        $user = $this->socialIdentityService->findUserByProfile($profile);

        if ($user === null) {
            return SocialAuthResultDTO::registrationRequired(
                registrationToken: $this->registrationTokenService->encode($profile),
                email: $profile->email,
                firstName: $profile->firstName,
                lastName: $profile->lastName,
            );
        }

        return $this->databaseManager->transaction(function () use ($user, $profile, $loginData) {
            $this->socialIdentityService->linkToUser($user, $profile);

            $accountContext = $this->accountContextService->resolve($user->getId(), $loginData->accountId);

            $this->accountContextService->recordLogin($user->getId(), $accountContext->accountId);

            return SocialAuthResultDTO::authenticated(new LoginResponse(
                accounts: $accountContext->accounts,
                token: $this->authTokenService->issueForUser(
                    userId: $user->getId(),
                    accountId: $accountContext->accountId,
                    role: $accountContext->role,
                ),
                user: $user,
                accountId: $accountContext->accountId,
            ));
        });
    }
}
