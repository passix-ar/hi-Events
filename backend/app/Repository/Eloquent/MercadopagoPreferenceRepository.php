<?php

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\MercadopagoPreferenceDomainObject;
use HiEvents\Models\MercadopagoPreference;
use HiEvents\Repository\Interfaces\MercadopagoPreferenceRepositoryInterface;

/**
 * @extends BaseRepository<MercadopagoPreferenceDomainObject>
 */
class MercadopagoPreferenceRepository extends BaseRepository implements MercadopagoPreferenceRepositoryInterface
{
    protected function getModel(): string
    {
        return MercadopagoPreference::class;
    }

    public function getDomainObject(): string
    {
        return MercadopagoPreferenceDomainObject::class;
    }
}
