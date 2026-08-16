<?php

// Added by Passix on 2026-05-25: MercadoPago Marketplace integration.
namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\AccountMercadopagoPlatformDomainObject;
use HiEvents\Models\AccountMercadopagoPlatform;
use HiEvents\Repository\Interfaces\AccountMercadopagoPlatformRepositoryInterface;
use Illuminate\Support\Facades\DB;

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
        // An expired access token counts as not connected: the scheduled refresh
        // (mercadopago:refresh-tokens) renews tokens before they expire, so an
        // expired one means the refresh chain broke and the organizer must
        // reconnect. A revoked connection (revoked_at set — MercadoPago rejected
        // the refresh with a terminal error) also counts as not connected.
        return AccountMercadopagoPlatform::where('account_id', $accountId)
            ->whereNotNull('setup_completed_at')
            ->whereNull('revoked_at')
            ->where(static fn($query) => $query
                ->whereNull('token_expires_at')
                ->orWhere('token_expires_at', '>', now()))
            ->exists();
    }

    public function withLockedRow(int $id, callable $operation): mixed
    {
        return DB::transaction(function () use ($id, $operation) {
            $model = AccountMercadopagoPlatform::query()
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            return $operation($model === null ? null : $this->handleSingleResult($model));
        });
    }
}
