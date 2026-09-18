<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Domain\Tools;

use HiEvents\Assistant\Domain\AssistantContext;
use HiEvents\Constants;
use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrganizerRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Domain\Product\AvailableProductQuantitiesFetchService;
use HiEvents\Services\Domain\Product\DTO\AvailableProductQuantitiesDTO;
use HiEvents\Services\Infrastructure\Authorization\IsAuthorizedService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Psr\Log\LoggerInterface;

/**
 * The facts needed to write promotional copy for an event (social posts,
 * WhatsApp broadcasts): dates in local time, venue, prices, and above all the
 * real public sale link. The model writes the text; this tool never does.
 */
class GetEventPromoKitTool extends AbstractAssistantTool
{
    private const DESCRIPTION_MAX_CHARS = 600;

    /**
     * Mirrors `organizerHomepagePath` in frontend/src/utilites/urlHelper.ts; the
     * backend has no config entry for the organizer public page.
     */
    private const ORGANIZER_HOMEPAGE_PATH = '/events/%d/%s';

    public function __construct(
        AssistantContext                                        $context,
        IsAuthorizedService                                     $isAuthorizedService,
        EventRepositoryInterface                                $events,
        LoggerInterface                                         $logger,
        private readonly ProductRepositoryInterface             $products,
        private readonly OrganizerRepositoryInterface           $organizers,
        private readonly AvailableProductQuantitiesFetchService $availableQuantities,
    )
    {
        parent::__construct($context, $isAuthorizedService, $events, $logger);
    }

    protected function configure(): void
    {
        $this
            ->as('get_event_promo_kit')
            ->for('Facts to write promotional copy for one event (Instagram caption, WhatsApp broadcast, tweet): '
                . 'title, local start/end date, venue, short description, ticket types with price and availability, '
                . 'whether it is published, and the real public sale URL to paste as the link. It returns data only; '
                . 'you write the text. Use find_events first if you only know the event name.')
            ->withNumberParameter('event_id', 'The event id.');
    }

    public function __invoke(int|float $event_id): string
    {
        $args = $this->validateArguments(['event_id' => $event_id], ['event_id' => 'required|integer|min:1']);

        $event = $this->authorizeEvent((int)$args['event_id']);
        $eventId = $event->getId();
        $timezone = $event->getTimezone() ?? $this->context->timezone;
        $isPublished = $event->getStatus() === EventStatus::LIVE->name;

        $payload = [
            'event' => [
                'id' => $eventId,
                'title' => $this->clip($event->getTitle(), 150),
                'status' => $event->getStatus(),
                'is_published' => $isPublished,
                'start_date' => $this->localDate($event->getStartDate(), $timezone),
                'end_date' => $this->localDate($event->getEndDate(), $timezone),
                'timezone' => $timezone,
                'location' => $this->location($event),
                'description' => $this->plainDescription($event),
                'category' => $event->getCategory(),
                'currency' => $event->getCurrency(),
            ],
            'tickets' => $this->tickets($eventId),
            'public_url' => $event->getEventUrl(),
            'organizer_name' => $this->clip($this->context->organizerName, 100),
        ];

        $organizerUrl = $this->organizerPublicUrl();
        if ($organizerUrl !== null) {
            $payload['organizer_public_url'] = $organizerUrl;
        }

        if (!$isPublished) {
            $payload['note'] = 'the page is not public until the event is published';
        }

        return $this->toJson($payload);
    }

    private function location(EventDomainObject $event): ?array
    {
        $details = $event->getLocationDetails();

        if (is_string($details)) {
            $details = json_decode($details, true);
        }

        if (!is_array($details)) {
            return null;
        }

        $location = array_filter([
            'venue_name' => $this->clip($details['venue_name'] ?? null, 100),
            'address' => $this->clip($details['address_line_1'] ?? null, 150),
            'city' => $this->clip($details['city'] ?? null, 85),
            'state' => $this->clip($details['state_or_region'] ?? null, 85),
            'country' => $this->clip($details['country'] ?? null, 85),
        ], static fn(?string $value): bool => $value !== null && $value !== '');

        return $location === [] ? null : $location;
    }

    private function plainDescription(EventDomainObject $event): ?string
    {
        $text = html_entity_decode(strip_tags((string)$event->getDescription()), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim($text);

        return $text === '' ? null : $this->clip($text, self::DESCRIPTION_MAX_CHARS);
    }

    /**
     * Availability comes from the same service the public homepage uses; the
     * numeric price comes from the product price rows, matched by price id.
     */
    private function tickets(int $eventId): array
    {
        $quantities = $this->availableQuantities->getAvailableProductQuantities($eventId, ignoreCache: true);

        /** @var Collection<ProductDomainObject> $products */
        $products = $this->products
            ->loadRelation(ProductPriceDomainObject::class)
            ->findWhere(['event_id' => $eventId]);

        $pricesById = $products
            ->filter(static fn(ProductDomainObject $p): bool => $p->getProductType() === ProductType::TICKET->name)
            ->flatMap(static fn(ProductDomainObject $p): Collection => $p->getProductPrices() ?? collect())
            ->keyBy(static fn(ProductPriceDomainObject $price): int => $price->getId());

        return $quantities->productQuantities
            ->filter(static fn(AvailableProductQuantitiesDTO $q): bool => $pricesById->has($q->price_id))
            ->map(function (AvailableProductQuantitiesDTO $q) use ($pricesById): array {
                /** @var ProductPriceDomainObject $price */
                $price = $pricesById->get($q->price_id);
                $available = $q->quantity_available >= Constants::INFINITE ? 'unlimited' : $q->quantity_available;

                return [
                    'product_id' => $q->product_id,
                    'title' => $this->clip($q->product_title),
                    'price_label' => $this->clip($q->price_label, 40),
                    'price' => $this->money($price->getPrice()),
                    'is_free' => $price->getPrice() <= 0,
                    'available' => $available,
                    'sold_out' => $available !== 'unlimited' && $available <= 0,
                ];
            })
            ->values()
            ->all();
    }

    private function organizerPublicUrl(): ?string
    {
        /** @var OrganizerDomainObject|null $organizer */
        $organizer = $this->organizers->findFirstWhere([
            'id' => $this->context->organizerId,
            'account_id' => $this->context->accountId,
        ]);

        if ($organizer === null) {
            return null;
        }

        return rtrim((string)config('app.frontend_url'), '/')
            . sprintf(self::ORGANIZER_HOMEPAGE_PATH, $organizer->getId(), $organizer->getSlug());
    }
}
