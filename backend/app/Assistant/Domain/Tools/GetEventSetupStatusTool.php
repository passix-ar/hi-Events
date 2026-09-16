<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Domain\Tools;

use HiEvents\Assistant\Domain\AssistantContext;
use HiEvents\DomainObjects\Enums\ImageType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\Repository\Interfaces\AccountMercadopagoPlatformRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
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
        $published = $event->getStatus() === EventStatus::LIVE->name;

        $checklist = [
            ['step' => 'tickets', 'done' => $ticketCount > 0, 'detail' => $ticketCount . ' ticket type(s)', 'route' => 'event_tickets'],
            ['step' => 'cover_image', 'done' => $hasCover, 'detail' => $hasCover ? 'has a cover' : 'no cover image', 'route' => 'event_settings'],
            ['step' => 'description', 'done' => $hasDescription, 'detail' => $hasDescription ? 'has a description' : 'no description', 'route' => 'event_settings'],
            ['step' => 'mercadopago', 'done' => $mercadoPagoConnected, 'detail' => $mercadoPagoConnected ? 'MercadoPago connected' : 'MercadoPago not connected: paid tickets cannot be sold', 'route' => 'connect_mercadopago'],
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
