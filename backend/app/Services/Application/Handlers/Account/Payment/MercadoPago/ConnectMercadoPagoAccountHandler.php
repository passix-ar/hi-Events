<?php

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
        $existing = $this->platformRepository->findFirstWhere([
            'account_id' => $command->accountId,
        ]);

        $isConnected = $existing?->isSetupComplete() ?? false;

        return new ConnectMercadoPagoAccountResponseDTO(
            authorizationUrl: $this->oauthService->buildAuthorizationUrl($command->accountId),
            isConnected: $isConnected,
        );
    }
}
