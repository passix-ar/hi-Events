<?php

// Added by Passix on 2026-05-25: MercadoPago Marketplace integration.
namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\MercadopagoPaymentDomainObject;
use HiEvents\Models\MercadopagoPayment;
use HiEvents\Repository\Interfaces\MercadopagoPaymentRepositoryInterface;

/**
 * @extends BaseRepository<MercadopagoPaymentDomainObject>
 */
class MercadopagoPaymentRepository extends BaseRepository implements MercadopagoPaymentRepositoryInterface
{
    protected function getModel(): string
    {
        return MercadopagoPayment::class;
    }

    public function getDomainObject(): string
    {
        return MercadopagoPaymentDomainObject::class;
    }
}
