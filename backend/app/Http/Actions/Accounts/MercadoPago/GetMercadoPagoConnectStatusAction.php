<?php

// Added by Passix on 2026-05-25: MercadoPago Marketplace integration.
namespace HiEvents\Http\Actions\Accounts\MercadoPago;

use HiEvents\DomainObjects\AccountDomainObject;
use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\Generated\AccountMercadopagoPlatformDomainObjectAbstract;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Repository\Interfaces\AccountMercadopagoPlatformRepositoryInterface;
use Illuminate\Http\JsonResponse;

class GetMercadoPagoConnectStatusAction extends BaseAction
{
    public function __construct(
        private readonly AccountMercadopagoPlatformRepositoryInterface $platformRepository,
    ) {
    }

    public function __invoke(int $account_id): JsonResponse
    {
        $this->isActionAuthorized($account_id, AccountDomainObject::class, Role::ADMIN);

        // Select only what the status needs. Loading the full row would decrypt the
        // token casts, so a single corrupted/legacy token would 500 this endpoint.
        $platform = $this->platformRepository->findFirstWhere(
            [AccountMercadopagoPlatformDomainObjectAbstract::ACCOUNT_ID => $account_id],
            [
                AccountMercadopagoPlatformDomainObjectAbstract::ID,
                AccountMercadopagoPlatformDomainObjectAbstract::ACCOUNT_ID,
                AccountMercadopagoPlatformDomainObjectAbstract::MP_USER_ID,
                AccountMercadopagoPlatformDomainObjectAbstract::SETUP_COMPLETED_AT,
            ],
        );

        return $this->jsonResponse([
            'is_connected'   => $platform?->isSetupComplete() ?? false,
            'mp_user_id'     => $platform?->getMpUserId(),
            'connected_at'   => $platform?->getSetupCompletedAt(),
        ]);
    }
}
