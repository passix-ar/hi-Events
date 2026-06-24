<?php

// Added by Passix on 2026-05-25: MercadoPago Marketplace integration.
namespace HiEvents\Services\Application\Handlers\Account\Payment\MercadoPago;

use HiEvents\Repository\Interfaces\AccountMercadopagoPlatformRepositoryInterface;
use HiEvents\Services\Application\Handlers\Account\Payment\MercadoPago\DTO\ConnectMercadoPagoAccountDTO;
use HiEvents\Services\Application\Handlers\Account\Payment\MercadoPago\DTO\ConnectMercadoPagoAccountResponseDTO;
use HiEvents\Services\Domain\Payment\MercadoPago\MercadoPagoOAuthService;

class ConnectMercadoPagoAccountHandler
{
    public function __construct(
        private readonly MercadoPagoOAuthService                      $oauthService,
        private readonly AccountMercadopagoPlatformRepositoryInterface $platformRepository,
    ) {
    }

    public function handle(ConnectMercadoPagoAccountDTO $command): ConnectMercadoPagoAccountResponseDTO
    {
        // Select only what we need: hydrating the full row would decrypt the token
        // casts, so a corrupted/legacy token would 500 the connect endpoint.
        $existing = $this->platformRepository->findFirstWhere(
            ['account_id' => $command->accountId],
            ['id', 'account_id', 'setup_completed_at'],
        );

        $isConnected = $existing?->isSetupComplete() ?? false;

        return new ConnectMercadoPagoAccountResponseDTO(
            authorizationUrl: $this->oauthService->buildAuthorizationUrl($command->accountId),
            isConnected: $isConnected,
        );
    }
}
