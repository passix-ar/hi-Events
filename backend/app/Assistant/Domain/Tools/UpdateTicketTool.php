<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Domain\Tools;

use HiEvents\Assistant\Domain\AssistantContext;
use HiEvents\DomainObjects\Enums\ProductPriceType;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\DomainObjects\TaxAndFeesDomainObject;
use HiEvents\Helper\DateHelper;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Application\Handlers\Product\DTO\UpsertProductDTO;
use HiEvents\Services\Application\Handlers\Product\EditProductHandler;
use HiEvents\Services\Infrastructure\Authorization\IsAuthorizedService;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/**
 * Changes a ticket type that already exists. EditProductHandler replaces the
 * product wholesale, so the current values are loaded and only the fields the
 * organizer asked for are overridden.
 */
class UpdateTicketTool extends AbstractAssistantWriteTool
{
    public const CONFIRMATION_PHRASE = 'MODIFICAR';

    public function __construct(
        AssistantContext                           $context,
        IsAuthorizedService                        $isAuthorizedService,
        EventRepositoryInterface                   $events,
        LoggerInterface                            $logger,
        private readonly ProductRepositoryInterface $products,
        private readonly EditProductHandler         $editProduct,
    )
    {
        parent::__construct($context, $isAuthorizedService, $events, $logger);
    }

    protected function configure(): void
    {
        $this
            ->as('update_ticket')
            ->for('Changes an existing ticket type: price, quantity for sale, name, max per order or description. '
                . 'Only the fields you pass change. Preview without confirm first. On a DRAFT event the organizer\'s '
                . 'yes is enough; on a PUBLISHED event (it is selling) they must reply with the exact word MODIFICAR, '
                . 'which you pass as confirmation_phrase. Use get_event_stats to find the ticket ids (product_id).')
            ->withNumberParameter('event_id', 'The event.')
            ->withNumberParameter('product_id', 'The ticket type to change.')
            ->withNumberParameter('price', 'New price in the event currency (0 = free).', required: false)
            ->withNumberParameter('quantity', 'New quantity for sale. Pass 0 for unlimited.', required: false)
            ->withStringParameter('title', 'New name (max 150).', required: false)
            ->withNumberParameter('max_per_order', 'New maximum per order.', required: false)
            ->withStringParameter('description', 'New description.', required: false)
            ->withBooleanParameter('confirm', 'True only after the organizer confirmed the preview.', required: false)
            ->withStringParameter('confirmation_phrase', 'The exact word the organizer typed (MODIFICAR) for a published event.', required: false);
    }

    public function __invoke(
        int|float      $event_id,
        int|float      $product_id,
        int|float|null $price = null,
        int|float|null $quantity = null,
        ?string        $title = null,
        int|float|null $max_per_order = null,
        ?string        $description = null,
        ?bool          $confirm = null,
        ?string        $confirmation_phrase = null,
    ): string
    {
        $args = $this->validateArguments(
            compact('event_id', 'product_id', 'price', 'quantity', 'title', 'max_per_order', 'description'),
            [
                'event_id' => 'required|integer|min:1',
                'product_id' => 'required|integer|min:1',
                'price' => 'nullable|numeric|min:0|max:99999999',
                'quantity' => 'nullable|integer|min:0|max:1000000',
                'title' => 'nullable|string|min:1|max:150',
                'max_per_order' => 'nullable|integer|min:1|max:1000',
                'description' => 'nullable|string|max:2000',
            ],
        );

        $event = $this->authorizeEvent((int)$args['event_id']);

        /** @var ProductDomainObject|null $product */
        // Taxes and fees ride along: EditProductHandler syncs the list it is given,
        // so leaving them out would strip the service fee on any edit.
        $product = $this->products
            ->loadRelation(ProductPriceDomainObject::class)
            ->loadRelation(TaxAndFeesDomainObject::class)
            ->findFirstWhere(['id' => (int)$args['product_id'], 'event_id' => $event->getId()]);

        if ($product === null) {
            return $this->toJson(['error' => 'ticket_not_found', 'details' => 'No ticket type with that id on this event.']);
        }

        if ($product->getType() === ProductPriceType::TIERED->name) {
            return $this->toJson(['error' => 'tiered_ticket', 'details' => 'Tiered tickets have several prices; edit them from the panel (get_panel_route event_tickets).']);
        }

        $changes = array_filter([
            'price' => $args['price'] === null ? null : round((float)$args['price'], 2),
            'quantity' => $args['quantity'] === null ? null : (int)$args['quantity'],
            'title' => $args['title'],
            'max_per_order' => $args['max_per_order'] === null ? null : (int)$args['max_per_order'],
            'description' => $args['description'],
        ], static fn($v) => $v !== null);

        if ($changes === []) {
            return $this->toJson(['error' => 'invalid_arguments', 'details' => 'Pass at least one field to change.']);
        }

        /** @var ProductPriceDomainObject $currentPrice */
        $currentPrice = $product->getProductPrices()->first();
        $isLive = $event->getStatus() === EventStatus::LIVE->name;

        $payload = [
            'event_id' => $event->getId(),
            'event_status' => $event->getStatus(),
            'ticket' => ['id' => $product->getId(), 'title' => $this->clip($product->getTitle())],
            'current' => [
                'price' => $this->money($currentPrice->getPrice()),
                'quantity' => $currentPrice->getInitialQuantityAvailable() ?? 'unlimited',
                'max_per_order' => $product->getMaxPerOrder(),
            ],
            'changes' => $changes,
            'double_check' => $isLive
                ? 'The event is selling: ask the organizer to reply with the exact word ' . self::CONFIRMATION_PHRASE . '.'
                : 'Draft event: a plain confirmation is enough.',
        ];

        if ($confirm !== true) {
            return $this->preview($payload);
        }

        if (($blocked = $this->doubleCheck($isLive, $confirm, $confirmation_phrase, self::CONFIRMATION_PHRASE)) !== null) {
            return $blocked;
        }

        $newPrice = $changes['price'] ?? (float)$currentPrice->getPrice();
        $newQuantity = array_key_exists('quantity', $changes)
            ? ($changes['quantity'] === 0 ? null : $changes['quantity'])
            : $currentPrice->getInitialQuantityAvailable();

        $this->editProduct->handle(UpsertProductDTO::fromArray([
            'account_id' => $this->context->accountId,
            'event_id' => $event->getId(),
            'product_id' => $product->getId(),
            'product_category_id' => $product->getProductCategoryId(),
            'title' => $changes['title'] ?? $product->getTitle(),
            'description' => array_key_exists('description', $changes) ? $changes['description'] : $product->getDescription(),
            'type' => $newPrice > 0 ? ProductPriceType::PAID->name : ProductPriceType::FREE->name,
            'product_type' => $product->getProductType(),
            'order' => $product->getOrder(),
            'sale_start_date' => $this->local($product->getSaleStartDate(), $event->getTimezone()),
            'sale_end_date' => $this->local($product->getSaleEndDate(), $event->getTimezone()),
            'max_per_order' => $changes['max_per_order'] ?? $product->getMaxPerOrder(),
            'min_per_order' => $product->getMinPerOrder(),
            'is_hidden' => (bool)$product->getIsHidden(),
            'hide_before_sale_start_date' => (bool)$product->getHideBeforeSaleStartDate(),
            'hide_after_sale_end_date' => (bool)$product->getHideAfterSaleEndDate(),
            'hide_when_sold_out' => (bool)$product->getHideWhenSoldOut(),
            'start_collapsed' => (bool)$product->getStartCollapsed(),
            'show_quantity_remaining' => (bool)$product->getShowQuantityRemaining(),
            'is_hidden_without_promo_code' => (bool)$product->getIsHiddenWithoutPromoCode(),
            'is_highlighted' => (bool)$product->getIsHighlighted(),
            'highlight_message' => $product->getHighlightMessage(),
            'waitlist_enabled' => $product->getWaitlistEnabled(),
            'tax_and_fee_ids' => $product->getTaxAndFees()->map(static fn(TaxAndFeesDomainObject $t): int => $t->getId())->all(),
            'prices' => [[
                'id' => $currentPrice->getId(),
                'price' => $newPrice,
                'label' => $currentPrice->getLabel(),
                'initial_quantity_available' => $newQuantity,
                'sale_start_date' => $this->local($currentPrice->getSaleStartDate(), $event->getTimezone()),
                'sale_end_date' => $this->local($currentPrice->getSaleEndDate(), $event->getTimezone()),
                'is_hidden' => (bool)$currentPrice->getIsHidden(),
            ]],
        ]));

        $this->context->entities->rememberTicket($product->getId(), $changes['title'] ?? $product->getTitle(), $event->getId());
        $this->logWrite('ticket_updated', ['event_id' => $event->getId(), 'product_id' => $product->getId(), 'changes' => $changes]);

        return $this->toJson([
            'status' => 'updated',
            'ticket' => ['id' => $product->getId(), 'title' => $changes['title'] ?? $product->getTitle()],
            'applied' => $changes,
        ]);
    }

    /**
     * Stored dates are UTC and the handler converts from the event timezone again,
     * so they go back as local wall time.
     */
    private function local(?string $storedUtc, ?string $timezone): ?string
    {
        if ($storedUtc === null) {
            return null;
        }

        return Carbon::parse($storedUtc, 'UTC')->setTimezone($timezone ?? $this->context->timezone)->format('Y-m-d H:i:s');
    }
}
