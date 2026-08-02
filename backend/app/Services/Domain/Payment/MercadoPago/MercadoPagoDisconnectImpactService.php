<?php

// Added by Passix: what breaks if an account disconnects MercadoPago.
namespace HiEvents\Services\Domain\Payment\MercadoPago;

use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use Illuminate\Support\Collection;

class MercadoPagoDisconnectImpactService
{
    public function __construct(
        private readonly EventRepositoryInterface $eventRepository,
    )
    {
    }

    /**
     * Published events that would be left with no way to charge, because MercadoPago
     * is their only payment method. Disconnecting would keep them on sale while every
     * purchase fails, so they block the operation until offline payments are enabled
     * or the event is unpublished.
     *
     * @return Collection<EventDomainObject>
     */
    public function getBlockingEvents(int $accountId): Collection
    {
        return $this->eventRepository
            ->loadRelation(new Relationship(EventSettingDomainObject::class))
            ->findWhere([
                'account_id' => $accountId,
                'status' => EventStatus::LIVE->name,
            ])
            ->filter(static function (EventDomainObject $event) {
                $providers = $event->getEventSettings()?->getPaymentProviders() ?? [];

                return in_array(PaymentProviders::MERCADOPAGO->value, $providers, true)
                    && !in_array(PaymentProviders::OFFLINE->value, $providers, true);
            })
            ->values();
    }

    /**
     * Published events that would stop offering MercadoPago but can still sell through
     * another method. Used to tell the organizer what changes, not to block them.
     *
     * @return Collection<EventDomainObject>
     */
    public function getAffectedEvents(int $accountId): Collection
    {
        return $this->eventRepository
            ->loadRelation(new Relationship(EventSettingDomainObject::class))
            ->findWhere([
                'account_id' => $accountId,
                'status' => EventStatus::LIVE->name,
            ])
            ->filter(static function (EventDomainObject $event) {
                $providers = $event->getEventSettings()?->getPaymentProviders() ?? [];

                return in_array(PaymentProviders::MERCADOPAGO->value, $providers, true);
            })
            ->values();
    }
}
