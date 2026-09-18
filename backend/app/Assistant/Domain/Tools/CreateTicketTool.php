<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Domain\Tools;

use HiEvents\Assistant\Domain\AssistantContext;
use HiEvents\DomainObjects\Enums\ProductPriceType;
use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\ProductCategoryDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductCategoryRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Application\Handlers\Product\CreateProductHandler;
use HiEvents\Services\Application\Handlers\Product\DTO\UpsertProductDTO;
use HiEvents\Services\Infrastructure\Authorization\IsAuthorizedService;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

class CreateTicketTool extends AbstractAssistantWriteTool
{
    public function __construct(
        AssistantContext                                    $context,
        IsAuthorizedService                                 $isAuthorizedService,
        EventRepositoryInterface                            $events,
        LoggerInterface                                     $logger,
        private readonly CreateProductHandler               $createProduct,
        private readonly ProductCategoryRepositoryInterface $categories,
        private readonly ProductRepositoryInterface         $products,
    )
    {
        parent::__construct($context, $isAuthorizedService, $events, $logger);
    }

    protected function configure(): void
    {
        $this
            ->as('create_ticket')
            ->for('Adds a ticket type to one of this organizer\'s events. A price of 0 creates a free ticket. '
                . 'Call it first without confirm to get a preview, show that to the organizer, and only call it '
                . 'again with confirm=true once they agree. On a published event the ticket goes on sale at once, '
                . 'so the organizer must reply MODIFICAR (pass it as confirmation_phrase). Sales run from '
                . 'sale_start_date (default: now) to sale_end_date (default: when the event ends).')
            ->withNumberParameter('event_id', 'The event the ticket belongs to.')
            ->withStringParameter('title', 'Ticket name, e.g. "General" or "VIP" (max 150 characters).')
            ->withNumberParameter('price', 'Price in the event currency. 0 for a free ticket.')
            ->withNumberParameter('quantity', 'How many are for sale. Omit for unlimited.', required: false)
            ->withStringParameter('description', 'What this ticket includes.', required: false)
            ->withNumberParameter('max_per_order', 'Maximum per order (default 100).', required: false)
            ->withStringParameter('sale_start_date', 'When sales open, "YYYY-MM-DD HH:MM" in the organizer timezone. Omit for now.', required: false)
            ->withStringParameter('sale_end_date', 'When sales close, "YYYY-MM-DD HH:MM". Omit to close when the event ends.', required: false)
            ->withBooleanParameter('confirm', 'Pass true only after the organizer confirmed the preview.', required: false)
            ->withStringParameter('confirmation_phrase', 'The exact word MODIFICAR typed by the organizer, only for published events.', required: false);
    }

    public function __invoke(
        int|float       $event_id,
        string          $title,
        int|float       $price,
        int|float|null  $quantity = null,
        ?string         $description = null,
        int|float|null  $max_per_order = null,
        ?string         $sale_start_date = null,
        ?string         $sale_end_date = null,
        ?bool           $confirm = null,
        ?string         $confirmation_phrase = null,
    ): string
    {
        $args = $this->validateArguments(
            [
                'event_id' => $event_id,
                'title' => $title,
                'price' => $price,
                'quantity' => $quantity,
                'description' => $description,
                'max_per_order' => $max_per_order,
                'sale_start_date' => $sale_start_date,
                'sale_end_date' => $sale_end_date,
            ],
            [
                'event_id' => 'required|integer|min:1',
                'title' => 'required|string|min:1|max:150',
                'price' => 'required|numeric|min:0|max:99999999',
                'quantity' => 'nullable|integer|min:1|max:1000000',
                'description' => 'nullable|string|max:2000',
                'max_per_order' => 'nullable|integer|min:1|max:1000',
                'sale_start_date' => 'nullable|date_format:Y-m-d H:i,Y-m-d H:i:s,Y-m-d',
                'sale_end_date' => 'nullable|date_format:Y-m-d H:i,Y-m-d H:i:s,Y-m-d',
            ],
        );

        $event = $this->authorizeEvent((int)$args['event_id']);

        // A ticket on a published event is on sale the moment it is written, so
        // that case takes the typed word, like every other change to a live event.
        $isLive = $event->getStatus() === EventStatus::LIVE->name;

        $price = round((float)$args['price'], 2);
        $timezone = $event->getTimezone() ?? $this->context->timezone;
        $saleEndDate = $args['sale_end_date'] !== null
            ? $this->wallTime($args['sale_end_date'], $timezone)
            : $this->saleEndDate($event);
        $saleStartDate = $args['sale_start_date'] !== null ? $this->wallTime($args['sale_start_date'], $timezone) : null;

        if ($saleStartDate !== null && $saleStartDate >= $saleEndDate) {
            return $this->toJson(['error' => 'invalid_arguments', 'details' => 'sale_start_date must be before sale_end_date.']);
        }

        $payload = [
            'event_id' => $event->getId(),
            'event_title' => $this->clip($event->getTitle()),
            'title' => $args['title'],
            'price' => $price,
            'currency' => $event->getCurrency(),
            'type' => $price > 0 ? ProductPriceType::PAID->name : ProductPriceType::FREE->name,
            'quantity' => $args['quantity'] === null ? 'unlimited' : (int)$args['quantity'],
            'on_sale_from' => $saleStartDate ?? 'now',
            'on_sale_until' => $saleEndDate,
            'event_status' => $event->getStatus(),
        ];

        $existing = $this->findExisting($event->getId(), $args['title']);

        if ($existing !== null) {
            $this->context->entities->rememberTicket($existing->getId(), $existing->getTitle(), $event->getId());

            return $this->toJson([
                'status' => 'already_exists',
                'ticket' => ['id' => $existing->getId(), 'title' => $this->clip($existing->getTitle())],
                'hint' => 'This event already has a ticket type with that name - most likely created earlier in this conversation. Nothing was created; treat it as done and continue with the next step.',
            ]);
        }

        if ($confirm !== true) {
            return $this->toJson([
                'status' => 'needs_confirmation',
                'would_create' => $payload,
                'hint' => $isLive
                    ? 'This event is published: the ticket goes on sale as soon as it is created. Show the preview and ask the organizer to reply with the exact word MODIFICAR before calling again with confirm=true and confirmation_phrase.'
                    : 'Show this to the organizer in their own words and ask for confirmation. Call again with confirm=true only after they agree.',
            ]);
        }

        if (($refusal = $this->doubleCheck($isLive, $confirm, $confirmation_phrase, 'MODIFICAR')) !== null) {
            return $refusal;
        }

        $category = $this->defaultCategory($event->getId());

        if ($category === null) {
            return $this->toJson(['error' => 'tool_failed', 'details' => 'The event has no ticket category yet.']);
        }

        $product = $this->createProduct->handle(UpsertProductDTO::fromArray([
            'account_id' => $this->context->accountId,
            'event_id' => $event->getId(),
            'product_category_id' => $category->getId(),
            'title' => $args['title'],
            'description' => $args['description'],
            'type' => $price > 0 ? ProductPriceType::PAID->name : ProductPriceType::FREE->name,
            'product_type' => ProductType::TICKET->name,
            'max_per_order' => $args['max_per_order'] ?? 100,
            'sale_start_date' => $saleStartDate,
            'sale_end_date' => $saleEndDate,
            'prices' => [
                [
                    'price' => $price,
                    'initial_quantity_available' => $args['quantity'] === null ? null : (int)$args['quantity'],
                ],
            ],
        ]));

        $this->context->entities->rememberTicket($product->getId(), $product->getTitle(), $event->getId());

        $this->logWrite('ticket_created', [
            'event_id' => $event->getId(),
            'product_id' => $product->getId(),
            'title' => $product->getTitle(),
            'price' => $price,
        ]);

        return $this->toJson([
            'status' => 'created',
            'ticket' => [
                'id' => $product->getId(),
                'title' => $this->clip($product->getTitle()),
                'price' => $price,
                'currency' => $event->getCurrency(),
                'quantity' => $payload['quantity'],
            ],
            'next_steps' => 'The ticket is on the event. Publishing the event is done by the organizer from the panel.',
        ]);
    }

    /**
     * UpsertProductRequest requires a sale_end_date for tickets, so leaving it
     * null would put the product in a state the panel cannot produce. Sales close
     * when the event ends, which is what an organizer picks anyway.
     *
     * CreateProductService runs this through DateHelper::convertToUTC with the
     * event timezone, so it has to be handed over as local wall time.
     */
    private function saleEndDate(EventDomainObject $event): string
    {
        $storedDate = $event->getEndDate() ?? $event->getStartDate();

        return Carbon::parse($storedDate, 'UTC')
            ->setTimezone($event->getTimezone() ?? $this->context->timezone)
            ->format('Y-m-d H:i:s');
    }

    /** Accepts the three date shapes the model sends; hands back local wall time for the handler. */
    private function wallTime(string $value, string $timezone): string
    {
        foreach (['Y-m-d H:i', 'Y-m-d H:i:s', 'Y-m-d'] as $format) {
            if (Carbon::canBeCreatedFromFormat($value, $format)) {
                return Carbon::createFromFormat($format, $value, $timezone)->format('Y-m-d H:i:s');
            }
        }

        return $value;
    }

    private function findExisting(int $eventId, string $title): ?ProductDomainObject
    {
        /** @var ProductDomainObject|null $product */
        $product = $this->products->findFirstWhere([
            'event_id' => $eventId,
            'title' => $title,
        ]);

        return $product;
    }

    private function defaultCategory(int $eventId): ?ProductCategoryDomainObject
    {
        /** @var ProductCategoryDomainObject|null $category */
        $category = $this->categories->findFirstWhere(['event_id' => $eventId]);

        return $category;
    }
}
