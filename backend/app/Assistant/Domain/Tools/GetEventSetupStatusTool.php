<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Domain\Tools;

use HiEvents\Assistant\Domain\AssistantContext;
use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\Repository\Interfaces\AccountMercadopagoPlatformRepositoryInterface;
use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\ImageRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Infrastructure\Authorization\IsAuthorizedService;
use Psr\Log\LoggerInterface;

/**
 * What is still missing before an event can sell. This is what turns the
 * assistant into a guide: after every step it can say what comes next and
 * hand over the link to do it.
 */
class GetEventSetupStatusTool extends AbstractAssistantTool
{
    public function __construct(
        AssistantContext                                       $context,
        IsAuthorizedService                                    $isAuthorizedService,
        EventRepositoryInterface                               $events,
        LoggerInterface                                        $logger,
        private readonly ProductRepositoryInterface            $products,
        private readonly ImageRepositoryInterface              $images,
        private readonly AccountMercadopagoPlatformRepositoryInterface $mercadoPago,
        private readonly EventSettingsRepositoryInterface       $eventSettings,
    )
    {
        parent::__construct($context, $isAuthorizedService, $events, $logger);
    }

    protected function configure(): void
    {
        $this
            ->as('get_event_setup_status')
            ->for('Checklist of what an event still needs before it can sell: tickets, cover image, description, '
                . 'MercadoPago connected on the account, and whether it is published. Returns the next step to '
                . 'suggest. Call it after creating or changing something, and whenever the organizer asks '
                . '"what is missing" or "what do I do now".')
            ->withNumberParameter('event_id', 'The event id.');
    }

    public function __invoke(int|float $event_id): string
    {
        $args = $this->validateArguments(['event_id' => $event_id], ['event_id' => 'required|integer|min:1']);
        $event = $this->authorizeEvent((int)$args['event_id']);

        $ticketCount = $this->products->findWhere(['event_id' => $event->getId()])->count();
        $hasCover = $this->images->findFirstWhere([
            'entity_id' => $event->getId(),
            'entity_type' => EventDomainObject::class,
            'type' => ImageType::EVENT_COVER->name,
        ]) !== null;
        $hasDescription = trim(strip_tags((string)$event->getDescription())) !== '';
        $mercadoPagoConnected = $this->mercadoPago->isSetupCompleteForAccount($this->context->accountId);
        /** @var EventSettingDomainObject|null $settings */
        $settings = $this->eventSettings->findFirstWhere(['event_id' => $event->getId()]);
        $offlineEnabled = in_array(PaymentProviders::OFFLINE->value, is_array($settings?->getPaymentProviders()) ? $settings->getPaymentProviders() : [], true);
        $paymentOk = $mercadoPagoConnected || $offlineEnabled;
        $paymentDetail = match (true) {
            $mercadoPagoConnected && $offlineEnabled => 'MercadoPago connected and offline payment enabled',
            $mercadoPagoConnected => 'MercadoPago connected',
            $offlineEnabled => 'offline payment (transfer/cash) enabled; MercadoPago not connected',
            default => 'no payment method: connect MercadoPago on the account, or enable offline payment (set_offline_payment)',
        };
        $published = $event->getStatus() === EventStatus::LIVE->name;
        $location = is_array($settings?->getLocationDetails()) ? array_filter($settings->getLocationDetails()) : [];
        $hasAddress = ($settings?->getIsOnlineEvent() ?? false) || (!empty($location['address_line_1']) && !empty($location['city']));
        $hasContact = trim((string)$settings?->getSupportEmail()) !== '';

        $checklist = [
            ['step' => 'tickets', 'done' => $ticketCount > 0, 'detail' => $ticketCount . ' ticket type(s)', 'route' => 'event_tickets'],
            ['step' => 'location', 'done' => $hasAddress, 'detail' => $hasAddress ? (($settings?->getIsOnlineEvent() ?? false) ? 'online event' : 'address set') : 'no street address yet: the page cannot show the map (set_event_location)', 'route' => 'event_settings'],
            ['step' => 'cover_image', 'done' => $hasCover, 'detail' => $hasCover ? 'has a cover' : 'no cover image', 'route' => 'event_settings'],
            ['step' => 'description', 'done' => $hasDescription, 'detail' => $hasDescription ? 'has a description' : 'no description', 'route' => 'event_settings'],
            ['step' => 'payment', 'done' => $paymentOk, 'detail' => $paymentDetail, 'route' => 'connect_mercadopago', 'mercadopago_connected' => $mercadoPagoConnected, 'offline_payment' => $offlineEnabled],
            ['step' => 'contact', 'done' => $hasContact, 'detail' => $hasContact ? 'support email set' : 'no support email for buyers (set_checkout_settings)', 'route' => 'event_settings'],
            ['step' => 'published', 'done' => $published, 'detail' => $published ? 'live' : 'still a draft', 'route' => 'publish_event'],
        ];

        $next = null;
        foreach ($checklist as $item) {
            if (!$item['done']) {
                $next = $item;
                break;
            }
        }

        return $this->toJson([
            'event_id' => $event->getId(),
            'event_title' => $this->clip($event->getTitle()),
            'status' => $event->getStatus(),
            'checklist' => $checklist,
            'ready_to_sell' => $next === null,
            'next_step' => $next === null
                ? 'Everything is in place.'
                : sprintf('%s — ask get_panel_route for "%s" and offer the link.', $next['detail'], $next['route']),
        ]);
    }
}
