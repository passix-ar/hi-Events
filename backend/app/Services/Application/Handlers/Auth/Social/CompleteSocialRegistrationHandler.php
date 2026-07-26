<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Auth\Social;

use HiEvents\Exceptions\EmailAlreadyExists;
use HiEvents\Exceptions\SocialAuth\InvalidIdTokenException;
use HiEvents\Exceptions\SocialAuth\SocialIdentityAlreadyLinkedException;
use HiEvents\Exceptions\UnauthorizedException;
use HiEvents\Repository\Interfaces\UserRepositoryInterface;
use HiEvents\Services\Application\Handlers\Account\CreateAccountHandler;
use HiEvents\Services\Application\Handlers\Account\DTO\CreateAccountDTO;
use HiEvents\Services\Application\Handlers\Auth\Social\DTO\CompleteSocialRegistrationDTO;
use HiEvents\Services\Domain\Auth\AuthTokenService;
use HiEvents\Services\Domain\Auth\DTO\LoginResponse;
use HiEvents\Services\Domain\Auth\SocialIdentityService;
use HiEvents\Services\Domain\Auth\SocialRegistrationTokenService;
use HiEvents\Services\Domain\Auth\UserAccountContextService;
use HiEvents\Services\Infrastructure\SocialAuth\DTO\SocialUserProfileDTO;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Throwable;

/**
 * Creates an account for a user who signed in with a social provider for the first time.
 */
readonly class CompleteSocialRegistrationHandler
{
    public function __construct(
        private SocialRegistrationTokenService $registrationTokenService,
        private CreateAccountHandler           $createAccountHandler,
        private SocialIdentityService          $socialIdentityService,
        private UserRepositoryInterface        $userRepository,
        private UserAccountContextService      $accountContextService,
        private AuthTokenService               $authTokenService,
        private DatabaseManager                $databaseManager,
    )
    {
    }

    /**
     * @throws InvalidIdTokenException
     * @throws EmailAlreadyExists
     * @throws SocialIdentityAlreadyLinkedException
     * @throws UnauthorizedException
     * @throws Throwable
     */
    public function handle(CompleteSocialRegistrationDTO $registrationData): LoginResponse
    {
        $profile = $this->registrationTokenService->decode($registrationData->registrationToken);

        return $this->databaseManager->transaction(function () use ($profile, $registrationData) {
            $account = $this->createAccountHandler->handle(
                $this->buildCreateAccountData($profile, $registrationData)
            );

            $user = $this->userRepository->findFirstWhere(['email' => $profile->email]);

            $this->socialIdentityService->linkToUser($user, $profile);

            $accountContext = $this->accountContextService->resolve($user->getId(), $account->getId());

            $this->accountContextService->recordLogin($user->getId(), $accountContext->accountId);

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
        });
    }

    private function buildCreateAccountData(
        SocialUserProfileDTO          $profile,
        CompleteSocialRegistrationDTO $registrationData,
    ): CreateAccountDTO
    {
        return CreateAccountDTO::fromArray([
            // Attacker-influenced values are spread first so the identity fields below
            // always win, whatever the allowlist happens to let through.
            ...$this->normaliseUtmData($registrationData->utmData),
            'business_name' => $registrationData->businessName,
            'timezone' => $registrationData->timezone,
            'currency_code' => $registrationData->currencyCode,
            'locale' => $registrationData->locale,
            'marketing_opt_in' => $registrationData->marketingOptIn,
            // Identity always comes from the signed token, never from the request body.
            'email' => $profile->email,
            'first_name' => $profile->firstName ?? Str::before($profile->email, '@'),
            'last_name' => $profile->lastName,
            'password' => null,
            'is_email_verified' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function normaliseUtmData(?array $utmData): array
    {
        if ($utmData === null) {
            return [];
        }

        $allowedKeys = [
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
            'referrer_url', 'landing_page', 'gclid', 'fbclid', 'utm_raw',
        ];

        return array_intersect_key($utmData, array_flip($allowedKeys));
    }
}
