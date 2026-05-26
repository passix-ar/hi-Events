<?php

namespace HiEvents\Services\Application\Handlers\Account\Payment\MercadoPago;

use Carbon\Carbon;
use HiEvents\DomainObjects\Generated\AccountMercadopagoPlatformDomainObjectAbstract;
use HiEvents\Exceptions\MercadoPago\MercadoPagoOAuthException;
use HiEvents\Repository\Interfaces\AccountMercadopagoPlatformRepositoryInterface;
use HiEvents\Services\Domain\Payment\MercadoPago\MercadoPagoOAuthService;
use Psr\Log\LoggerInterface;
use Throwable;

class MercadoPagoOAuthCallbackHandler
{
    public function __construct(
        private readonly MercadoPagoOAuthService                      $oauthService,
        private readonly AccountMercadopagoPlatformRepositoryInterface $platformRepository,
        private readonly LoggerInterface                               $logger,
    ) {
    }

    /**
     * @throws MercadoPagoOAuthException
     * @throws Throwable
     */
    public function handle(string $code, string $state): void
    {
        $accountId = $this->oauthService->decodeState($state);
        $tokenData = $this->oauthService->exchangeCodeForToken($code);

        $this->logger->info('MercadoPago OAuth callback received', [
            'account_id'  => $accountId,
            'mp_user_id'  => $tokenData['user_id'] ?? null,
        ]);

        $existing = $this->platformRepository->findFirstWhere([
            AccountMercadopagoPlatformDomainObjectAbstract::ACCOUNT_ID => $accountId,
        ]);

        $expiresAt = isset($tokenData['expires_in'])
            ? Carbon::now()->addSeconds($tokenData['expires_in'])->toDateTimeString()
            : null;

        $attributes = [
            AccountMercadopagoPlatformDomainObjectAbstract::ACCOUNT_ID        => $accountId,
            AccountMercadopagoPlatformDomainObjectAbstract::MP_USER_ID        => (string) ($tokenData['user_id'] ?? ''),
            AccountMercadopagoPlatformDomainObjectAbstract::ACCESS_TOKEN      => $tokenData['access_token'] ?? null,
            AccountMercadopagoPlatformDomainObjectAbstract::REFRESH_TOKEN     => $tokenData['refresh_token'] ?? null,
            AccountMercadopagoPlatformDomainObjectAbstract::PUBLIC_KEY        => $tokenData['public_key'] ?? null,
            AccountMercadopagoPlatformDomainObjectAbstract::TOKEN_EXPIRES_AT  => $expiresAt,
            AccountMercadopagoPlatformDomainObjectAbstract::SETUP_COMPLETED_AT => now()->toDateTimeString(),
        ];

        if ($existing) {
            $this->platformRepository->updateFromArray($existing->getId(), $attributes);
        } else {
            $this->platformRepository->create($attributes);
        }
    }
}
