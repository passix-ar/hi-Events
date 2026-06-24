<?php

// Added by Passix on 2026-05-25: MercadoPago Marketplace integration.
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

        $mpUserId = (string) ($tokenData['user_id'] ?? '');

        // Reconnecting must be idempotent. The unique constraint on mp_user_id also
        // covers soft-deleted rows in Postgres, so a plain insert fails whenever this
        // MercadoPago seller was connected before — under this account, another
        // account, or a since-disconnected (soft-deleted) row. Look the seller up by
        // mp_user_id first (including trashed) and update that row in place; only fall
        // back to the account's own row when the seller id is unknown.
        $existing = $mpUserId !== ''
            ? $this->platformRepository
                ->includeDeleted()
                ->findFirstWhere([AccountMercadopagoPlatformDomainObjectAbstract::MP_USER_ID => $mpUserId])
            : null;

        $existing ??= $this->platformRepository->findFirstWhere([
            AccountMercadopagoPlatformDomainObjectAbstract::ACCOUNT_ID => $accountId,
        ]);

        $expiresAt = isset($tokenData['expires_in'])
            ? Carbon::now()->addSeconds($tokenData['expires_in'])->toDateTimeString()
            : null;

        $attributes = [
            AccountMercadopagoPlatformDomainObjectAbstract::ACCOUNT_ID        => $accountId,
            AccountMercadopagoPlatformDomainObjectAbstract::MP_USER_ID        => $mpUserId,
            AccountMercadopagoPlatformDomainObjectAbstract::ACCESS_TOKEN      => $tokenData['access_token'] ?? null,
            AccountMercadopagoPlatformDomainObjectAbstract::REFRESH_TOKEN     => $tokenData['refresh_token'] ?? null,
            AccountMercadopagoPlatformDomainObjectAbstract::PUBLIC_KEY        => $tokenData['public_key'] ?? null,
            AccountMercadopagoPlatformDomainObjectAbstract::TOKEN_EXPIRES_AT  => $expiresAt,
            AccountMercadopagoPlatformDomainObjectAbstract::SETUP_COMPLETED_AT => now()->toDateTimeString(),
        ];

        if ($existing) {
            // Direct UPDATE (works for active or soft-deleted rows) that also restores
            // the row, claiming the connection for the account completing the OAuth.
            $this->platformRepository
                ->includeDeleted()
                ->updateWhere(
                    $attributes + [AccountMercadopagoPlatformDomainObjectAbstract::DELETED_AT => null],
                    [AccountMercadopagoPlatformDomainObjectAbstract::ID => $existing->getId()],
                );
        } else {
            $this->platformRepository->create($attributes);
        }
    }
}
