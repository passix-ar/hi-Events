<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Auth;

use HiEvents\DomainObjects\UserDomainObject;
use HiEvents\DomainObjects\UserSocialIdentityDomainObject;
use HiEvents\Exceptions\SocialAuth\SocialIdentityAlreadyLinkedException;
use HiEvents\Repository\Interfaces\UserRepositoryInterface;
use HiEvents\Repository\Interfaces\UserSocialIdentityRepositoryInterface;
use HiEvents\Services\Infrastructure\SocialAuth\DTO\SocialUserProfileDTO;

/**
 * Maps a verified social profile onto a Passix user.
 *
 * Matching is by provider user id first and email second. Email matching is only safe
 * because the verifier guarantees the provider confirmed ownership of that address.
 */
readonly class SocialIdentityService
{
    public function __construct(
        private UserSocialIdentityRepositoryInterface $socialIdentityRepository,
        private UserRepositoryInterface               $userRepository,
    )
    {
    }

    public function findUserByProfile(SocialUserProfileDTO $profile): ?UserDomainObject
    {
        $identity = $this->findIdentity($profile);

        if ($identity !== null) {
            return $this->userRepository->findFirstWhere(['id' => $identity->getUserId()]);
        }

        return $this->userRepository->findFirstWhere(['email' => $profile->email]);
    }

    /**
     * Links the profile to the user, or refreshes the existing link's login timestamp.
     *
     * @throws SocialIdentityAlreadyLinkedException
     */
    public function linkToUser(UserDomainObject $user, SocialUserProfileDTO $profile): UserSocialIdentityDomainObject
    {
        $identity = $this->findIdentity($profile);

        if ($identity !== null) {
            $this->assertIdentityBelongsTo($identity, $user);

            $this->socialIdentityRepository->updateWhere(
                attributes: [
                    'last_login_at' => now()->toDateTimeString(),
                    'email' => $profile->email,
                ],
                where: ['id' => $identity->getId()],
            );

            return $identity;
        }

        $this->assertUserHasNoOtherIdentityForProvider($user, $profile);

        return $this->socialIdentityRepository->create([
            'user_id' => $user->getId(),
            'provider' => $profile->provider->value,
            'provider_user_id' => $profile->providerUserId,
            'email' => $profile->email,
            'last_login_at' => now()->toDateTimeString(),
        ]);
    }

    private function findIdentity(SocialUserProfileDTO $profile): ?UserSocialIdentityDomainObject
    {
        return $this->socialIdentityRepository->findFirstWhere([
            'provider' => $profile->provider->value,
            'provider_user_id' => $profile->providerUserId,
        ]);
    }

    /**
     * @throws SocialIdentityAlreadyLinkedException
     */
    private function assertIdentityBelongsTo(UserSocialIdentityDomainObject $identity, UserDomainObject $user): void
    {
        if ($identity->getUserId() !== $user->getId()) {
            throw new SocialIdentityAlreadyLinkedException(
                __('This Google account is already linked to another user. Please contact support.')
            );
        }
    }

    /**
     * A user keeps one identity per provider. A second one means the provider reissued a
     * different account for the same address, which we cannot safely resolve on their behalf.
     *
     * @throws SocialIdentityAlreadyLinkedException
     */
    private function assertUserHasNoOtherIdentityForProvider(
        UserDomainObject     $user,
        SocialUserProfileDTO $profile,
    ): void
    {
        $existing = $this->socialIdentityRepository->findFirstWhere([
            'user_id' => $user->getId(),
            'provider' => $profile->provider->value,
        ]);

        if ($existing !== null) {
            throw new SocialIdentityAlreadyLinkedException(
                __('Your Passix account is already linked to a different Google account. Please contact support.')
            );
        }
    }
}
