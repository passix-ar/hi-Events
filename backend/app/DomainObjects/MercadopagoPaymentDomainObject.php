<?php

// Added by Passix on 2026-05-25: MercadoPago Marketplace integration.
namespace HiEvents\DomainObjects;

class MercadopagoPaymentDomainObject extends Generated\MercadopagoPaymentDomainObjectAbstract
{
    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isRejected(): bool
    {
        return in_array($this->status, ['rejected', 'cancelled', 'refunded', 'charged_back'], true);
    }
}
