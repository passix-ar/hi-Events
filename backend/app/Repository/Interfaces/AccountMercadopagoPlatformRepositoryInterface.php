<?php

// Added by Passix on 2026-05-25: MercadoPago Marketplace integration.
namespace HiEvents\Repository\Interfaces;

use HiEvents\DomainObjects\AccountMercadopagoPlatformDomainObject;

/**
 * @extends RepositoryInterface<AccountMercadopagoPlatformDomainObject>
 */
interface AccountMercadopagoPlatformRepositoryInterface extends RepositoryInterface
{
    /**
     * Permanently remove an account's MercadoPago connection. Hard delete (not soft)
     * so the unique mp_user_id is freed and the seller can reconnect afterwards.
     */
    public function forceDeleteByAccountId(int $accountId): void;

    /**
     * Whether the account has a fully-connected MercadoPago account. Queries only
     * setup_completed_at: hydrating the full row would run the encrypted token
     * casts, so a single corrupted/legacy token would make the check blow up.
     */
    public function isSetupCompleteForAccount(int $accountId): bool;
}
