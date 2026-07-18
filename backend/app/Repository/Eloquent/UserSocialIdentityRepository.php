<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\UserSocialIdentityDomainObject;
use HiEvents\Models\UserSocialIdentity;
use HiEvents\Repository\Interfaces\UserSocialIdentityRepositoryInterface;

/**
 * @extends BaseRepository<UserSocialIdentityDomainObject>
 */
class UserSocialIdentityRepository extends BaseRepository implements UserSocialIdentityRepositoryInterface
{
    public function getModel(): string
    {
        return UserSocialIdentity::class;
    }

    public function getDomainObject(): string
    {
        return UserSocialIdentityDomainObject::class;
    }
}
