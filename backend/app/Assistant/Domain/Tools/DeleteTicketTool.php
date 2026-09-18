<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Domain\Tools;

use HiEvents\Assistant\Domain\AssistantContext;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\Exceptions\CannotDeleteEntityException;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Application\Handlers\Product\DeleteProductHandler;
use HiEvents\Services\Infrastructure\Authorization\IsAuthorizedService;
use Psr\Log\LoggerInterface;

class DeleteTicketTool extends AbstractAssistantWriteTool
{
    public const CONFIRMATION_PHRASE = 'ELIMINAR';

    public function __construct(
        AssistantContext                            $context,
        IsAuthorizedService                         $isAuthorizedService,
        EventRepositoryInterface                    $events,
        LoggerInterface                             $logger,
        private readonly ProductRepositoryInterface $products,
        private readonly DeleteProductHandler       $deleteProduct,
    )
    {
        parent::__construct($context, $isAuthorizedService, $events, $logger);
    }

    protected function configure(): void
    {
        $this
            ->as('delete_ticket')
            ->for('Deletes a ticket type that has no sales. Deleting cannot be undone, so it always needs the exact '
                . 'word ELIMINAR typed by the organizer, passed as confirmation_phrase, after a preview. The platform '
                . 'refuses to delete a ticket type that already sold; then suggest hiding it from the panel instead.')
            ->withNumberParameter('event_id', 'The event.')
            ->withNumberParameter('product_id', 'The ticket type to delete.')
            ->withBooleanParameter('confirm', 'True only after the organizer confirmed the preview.', required: false)
            ->withStringParameter('confirmation_phrase', 'The exact word the organizer typed (ELIMINAR).', required: false);
    }

    public function __invoke(int|float $event_id, int|float $product_id, ?bool $confirm = null, ?string $confirmation_phrase = null): string
    {
        $args = $this->validateArguments(
            ['event_id' => $event_id, 'product_id' => $product_id],
            ['event_id' => 'required|integer|min:1', 'product_id' => 'required|integer|min:1'],
        );

        $event = $this->authorizeEvent((int)$args['event_id']);

        /** @var ProductDomainObject|null $product */
        $product = $this->products->findFirstWhere(['id' => (int)$args['product_id'], 'event_id' => $event->getId()]);

        if ($product === null) {
            return $this->toJson(['error' => 'ticket_not_found', 'details' => 'No ticket type with that id on this event.']);
        }

        $payload = [
            'event' => ['id' => $event->getId(), 'title' => $this->clip($event->getTitle())],
            'ticket' => ['id' => $product->getId(), 'title' => $this->clip($product->getTitle())],
            'irreversible' => true,
            'double_check' => 'Ask the organizer to reply with the exact word ' . self::CONFIRMATION_PHRASE . '.',
        ];

        if ($confirm !== true) {
            return $this->preview($payload);
        }

        if (($blocked = $this->doubleCheck(true, $confirm, $confirmation_phrase, self::CONFIRMATION_PHRASE)) !== null) {
            return $blocked;
        }

        try {
            $this->deleteProduct->handle($product->getId(), $event->getId());
        } catch (CannotDeleteEntityException $e) {
            return $this->toJson(['error' => 'cannot_delete', 'details' => $e->getMessage(), 'hint' => 'Offer to hide it instead (update_ticket cannot do that; it is done from the panel, get_panel_route event_tickets).']);
        }

        $this->logWrite('ticket_deleted', ['event_id' => $event->getId(), 'product_id' => $product->getId(), 'title' => $product->getTitle()]);

        return $this->toJson(['status' => 'deleted', 'ticket' => $payload['ticket']]);
    }
}
