<?php

// Added by Passix: disconnect an account's MercadoPago connection.
namespace HiEvents\Services\Application\Handlers\Account\Payment\MercadoPago;

use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Exceptions\MercadoPago\CannotDisconnectMercadoPagoException;
use HiEvents\Repository\Interfaces\AccountMercadopagoPlatformRepositoryInterface;
use HiEvents\Services\Domain\Payment\MercadoPago\MercadoPagoDisconnectImpactService;

class DisconnectMercadoPagoAccountHandler
{
    public function __construct(
        private readonly AccountMercadopagoPlatformRepositoryInterface $platformRepository,
        private readonly MercadoPagoDisconnectImpactService            $disconnectImpactService,
    ) {
    }

    /**
     * @throws CannotDisconnectMercadoPagoException
     */
    public function handle(int $accountId): void
    {
        $blockingEvents = $this->disconnectImpactService->getBlockingEvents($accountId);

        if ($blockingEvents->isNotEmpty()) {
            throw new CannotDisconnectMercadoPagoException(__(
                'These published events only accept MercadoPago and would stop selling: :events. Enable offline payments or unpublish them first.',
                ['events' => $blockingEvents->map(fn(EventDomainObject $event) => $event->getTitle())->join(', ')],
            ));
        }

        // Hard delete so the unique mp_user_id is freed and the seller can reconnect
        // (here or on another account) afterwards.
        $this->platformRepository->forceDeleteByAccountId($accountId);
    }
}
