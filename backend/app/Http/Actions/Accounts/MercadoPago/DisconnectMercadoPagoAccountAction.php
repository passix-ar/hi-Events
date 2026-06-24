<?php

// Added by Passix: disconnect an account's MercadoPago connection.
namespace HiEvents\Http\Actions\Accounts\MercadoPago;

use HiEvents\DomainObjects\AccountDomainObject;
use HiEvents\DomainObjects\Enums\Role;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Application\Handlers\Account\Payment\MercadoPago\DisconnectMercadoPagoAccountHandler;
use Illuminate\Http\JsonResponse;
use Throwable;

class DisconnectMercadoPagoAccountAction extends BaseAction
{
    public function __construct(
        private readonly DisconnectMercadoPagoAccountHandler $handler,
    ) {
    }

    /**
     * @throws Throwable
     */
    public function __invoke(int $account_id): JsonResponse
    {
        $this->isActionAuthorized($account_id, AccountDomainObject::class, Role::ADMIN);

        $this->handler->handle($account_id);

        return $this->jsonResponse(['is_connected' => false]);
    }
}
