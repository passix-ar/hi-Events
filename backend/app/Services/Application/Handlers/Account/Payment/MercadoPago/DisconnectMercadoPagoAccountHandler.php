<?php

// Added by Passix: disconnect an account's MercadoPago connection.
namespace HiEvents\Services\Application\Handlers\Account\Payment\MercadoPago;

use HiEvents\Repository\Interfaces\AccountMercadopagoPlatformRepositoryInterface;

class DisconnectMercadoPagoAccountHandler
{
    public function __construct(
        private readonly AccountMercadopagoPlatformRepositoryInterface $platformRepository,
    ) {
    }

    public function handle(int $accountId): void
    {
        // Hard delete so the unique mp_user_id is freed and the seller can reconnect
        // (here or on another account) afterwards.
        $this->platformRepository->forceDeleteByAccountId($accountId);
    }
}
