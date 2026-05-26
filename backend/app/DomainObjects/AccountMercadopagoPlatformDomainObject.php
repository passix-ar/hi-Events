<?php

namespace HiEvents\DomainObjects;

class AccountMercadopagoPlatformDomainObject extends Generated\AccountMercadopagoPlatformDomainObjectAbstract
{
    public function isSetupComplete(): bool
    {
        return $this->setup_completed_at !== null;
    }
}
