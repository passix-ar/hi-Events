<?php

// Added by Passix on 2026-05-25: MercadoPago Marketplace integration.
namespace HiEvents\DomainObjects;

class AccountMercadopagoPlatformDomainObject extends Generated\AccountMercadopagoPlatformDomainObjectAbstract
{
    public function isSetupComplete(): bool
    {
        return $this->setup_completed_at !== null;
    }
}
