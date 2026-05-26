<?php

// Added by Passix on 2026-05-25: MercadoPago Marketplace integration.
namespace HiEvents\Http\Actions\Accounts\MercadoPago;

use HiEvents\DomainObjects\AccountDomainObject;
use HiEvents\DomainObjects\Enums\Role;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\Account\Payment\MercadoPago\ConnectMercadoPagoAccountHandler;
use HiEvents\Services\Application\Handlers\Account\Payment\MercadoPago\DTO\ConnectMercadoPagoAccountDTO;
use Illuminate\Http\JsonResponse;
use Throwable;

class ConnectMercadoPagoAccountAction extends BaseAction
{
    public function __construct(
        private readonly ConnectMercadoPagoAccountHandler $handler,
    ) {
    }

    /**
     * @throws Throwable
     */
    public function __invoke(int $account_id): JsonResponse
    {
        $this->isActionAuthorized($account_id, AccountDomainObject::class, Role::ADMIN);

        $result = $this->handler->handle(new ConnectMercadoPagoAccountDTO(accountId: $account_id));

        return $this->jsonResponse([
            'authorization_url' => $result->authorizationUrl,
            'is_connected'      => $result->isConnected,
        ]);
    }
}
