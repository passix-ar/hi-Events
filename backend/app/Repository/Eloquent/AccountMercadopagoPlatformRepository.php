<?php

// Added by Passix on 2026-05-25: MercadoPago Marketplace integration.
namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\AccountMercadopagoPlatformDomainObject;
use HiEvents\Models\AccountMercadopagoPlatform;
use HiEvents\Repository\Interfaces\AccountMercadopagoPlatformRepositoryInterface;

/**
 * @extends BaseRepository<AccountMercadopagoPlatformDomainObject>
 */
class AccountMercadopagoPlatformRepository extends BaseRepository implements AccountMercadopagoPlatformRepositoryInterface
{
    protected function getModel(): string
    {
        return AccountMercadopagoPlatform::class;
    }

    public function getDomainObject(): string
    {
        return AccountMercadopagoPlatformDomainObject::class;
    }

    public function forceDeleteByAccountId(int $accountId): void
    {
        AccountMercadopagoPlatform::withTrashed()
            ->where('account_id', $accountId)
            ->forceDelete();
    }

    public function isSetupCompleteForAccount(int $accountId): bool
    {
        // Only the connection columns are touched: hydrating the row would decrypt
        // the token casts, so a corrupted/legacy token would blow up every caller.
        // An expired access token counts as not connected — MercadoPago rejects it
        // and there is no refresh flow, so reconnecting is the only way out.
        return AccountMercadopagoPlatform::where('account_id', $accountId)
            ->whereNotNull('setup_completed_at')
            ->where(static fn($query) => $query
                ->whereNull('token_expires_at')
                ->orWhere('token_expires_at', '>', now()))
            ->exists();
    }
}
