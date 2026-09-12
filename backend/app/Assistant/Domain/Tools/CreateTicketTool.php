<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Domain\Tools;

use HiEvents\Assistant\Domain\AssistantContext;
use HiEvents\DomainObjects\Enums\ProductPriceType;
use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\DomainObjects\ProductCategoryDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductCategoryRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Application\Handlers\Product\CreateProductHandler;
use HiEvents\Services\Application\Handlers\Product\DTO\UpsertProductDTO;
use HiEvents\Services\Infrastructure\Authorization\IsAuthorizedService;
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
                . 'again with confirm=true once they agree. It does not publish anything: a draft event stays '
                . 'a draft. Use find_events to get the event_id.')
            ->withNumberParameter('event_id', 'The event the ticket belongs to.')
            ->withStringParameter('title', 'Ticket name, e.g. "General" or "VIP" (max 150 characters).')
            ->withNumberParameter('price', 'Price in the event currency. 0 for a free ticket.')
            ->withNumberParameter('quantity', 'How many are for sale. Omit for unlimited.', required: false)
            ->withStringParameter('description', 'What this ticket includes.', required: false)
            ->withNumberParameter('max_per_order', 'Maximum per order (default 100).', required: false)
            ->withBooleanParameter('confirm', 'Pass true only after the organizer confirmed the preview.', required: false);
    }

    public function __invoke(
        int|float       $event_id,
        string          $title,
        int|float       $price,
        int|float|null  $quantity = null,
        ?string         $description = null,
        int|float|null  $max_per_order = null,
        ?bool           $confirm = null,
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
            ],
            [
                'event_id' => 'required|integer|min:1',
                'title' => 'required|string|min:1|max:150',
                'price' => 'required|numeric|min:0|max:99999999',
                'quantity' => 'nullable|integer|min:1|max:1000000',
                'description' => 'nullable|string|max:2000',
                'max_per_order' => 'nullable|integer|min:1|max:1000',
            ],
        );

        $event = $this->authorizeEvent((int)$args['event_id']);
        $price = round((float)$args['price'], 2);

        $payload = [
            'event_id' => $event->getId(),
            'event_title' => $this->clip($event->getTitle()),
            'title' => $args['title'],
            'price' => $price,
            'currency' => $event->getCurrency(),
            'type' => $price > 0 ? ProductPriceType::PAID->name : ProductPriceType::FREE->name,
            'quantity' => $args['quantity'] === null ? 'unlimited' : (int)$args['quantity'],
        ];

        $existing = $this->findExisting($event->getId(), $args['title']);

        if ($existing !== null) {
            return $this->toJson([
                'status' => 'already_exists',
                'ticket' => ['id' => $existing->getId(), 'title' => $this->clip($existing->getTitle())],
                'hint' => 'This event already has a ticket type with that name; nothing was created.',
            ]);
        }

        if ($confirm !== true) {
            return $this->preview($payload);
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
            'prices' => [
                [
                    'price' => $price,
                    'initial_quantity_available' => $args['quantity'] === null ? null : (int)$args['quantity'],
                ],
            ],
        ]));

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
