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
     * Whether the account holds a MercadoPago connection that can still charge.
     * Queries only the connection columns: hydrating the full row would run the
     * encrypted token casts, so a single corrupted/legacy token would make the
     * check blow up. An expired or revoked access token counts as not connected.
     */
    public function isSetupCompleteForAccount(int $accountId): bool;

    /**
     * Run $operation with the row held under SELECT ... FOR UPDATE inside a
     * transaction, serializing concurrent refreshes of the same connection —
     * MercadoPago refresh tokens are single-use, so two overlapping refreshes
     * would burn the chain. $operation receives the freshly read domain object
     * (null if the row vanished) and its return value is passed through.
     *
     * @param callable(AccountMercadopagoPlatformDomainObject|null): mixed $operation
     */
    public function withLockedRow(int $id, callable $operation): mixed;
}
