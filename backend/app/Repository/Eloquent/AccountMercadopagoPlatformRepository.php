<?php

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\AccountMercadopagoPlatformDomainObject;
use HiEvents\Models\AccountMercadopagoPlatform;
use HiEvents\Repository\Interfaces\AccountMercadopagoPlatformRepositoryInterface;

/**
 * @extends BaseRepository<AccountMercadopagoPlatformDomainObject>
 */
class AccountMercadopagoPlatformRepository extends BaseRepository implements AccountMercadopagoPlatformRepositoryInterface
{
    protected function getModel(): string
    {
        return AccountMercadopagoPlatform::class;
    }

    public function getDomainObject(): string
    {
        return AccountMercadopagoPlatformDomainObject::class;
    }
}
