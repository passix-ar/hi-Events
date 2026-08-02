<?php

// Added by Passix: what disconnecting MercadoPago would break, asked before the fact.
namespace HiEvents\Http\Actions\Accounts\MercadoPago;

use HiEvents\DomainObjects\AccountDomainObject;
use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Domain\Payment\MercadoPago\MercadoPagoDisconnectImpactService;
use Illuminate\Http\JsonResponse;

class GetMercadoPagoDisconnectStatusAction extends BaseAction
{
    public function __construct(
        private readonly MercadoPagoDisconnectImpactService $disconnectImpactService,
    ) {
    }

    public function __invoke(int $account_id): JsonResponse
    {
        $this->isActionAuthorized($account_id, AccountDomainObject::class, Role::ADMIN);

        $blockingEvents = $this->disconnectImpactService->getBlockingEvents($account_id);
        $affectedEvents = $this->disconnectImpactService->getAffectedEvents($account_id);
        $canDisconnect = $blockingEvents->isEmpty();

        return $this->jsonResponse([
            'data' => [
                'can_disconnect' => $canDisconnect,
                'reason' => $canDisconnect
                    ? null
                    : __('These published events only accept MercadoPago and would stop selling. Enable offline payments or unpublish them first.'),
                'blocking_events' => $this->toEventSummaries($blockingEvents),
                'affected_events' => $this->toEventSummaries($affectedEvents),
            ],
        ]);
    }

    private function toEventSummaries(iterable $events): array
    {
        return collect($events)
            ->map(static fn(EventDomainObject $event) => [
                'id' => $event->getId(),
                'title' => $event->getTitle(),
            ])
            ->all();
    }
}
