<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Domain\Tools;

use HiEvents\Assistant\Domain\AssistantContext;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\SeatingSectionDomainObject;
use HiEvents\Http\DTO\QueryParamsDTO;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Repository\Interfaces\SeatingSectionRepositoryInterface;
use HiEvents\Services\Infrastructure\Authorization\IsAuthorizedService;
use Psr\Log\LoggerInterface;

class GetSeatingSectionsTool extends AbstractAssistantTool
{
    public function __construct(
        AssistantContext                                   $context,
        IsAuthorizedService                                $isAuthorizedService,
        EventRepositoryInterface                           $events,
        LoggerInterface                                    $logger,
        private readonly SeatingSectionRepositoryInterface $sections,
        private readonly ProductRepositoryInterface        $products,
    )
    {
        parent::__construct($context, $isAuthorizedService, $events, $logger);
    }

    protected function configure(): void
    {
        $this
            ->as('get_seating_sections')
            ->for('Lists the sections of the event seat map (name, rows x seats, which ticket sells there). '
                . 'Empty means general admission: no seat map yet.')
            ->withNumberParameter('event_id', 'The event.');
    }

    public function __invoke(int|float $event_id): string
    {
        $args = $this->validateArguments(['event_id' => $event_id], ['event_id' => 'required|integer|min:1']);
        $event = $this->authorizeEvent((int)$args['event_id']);

        $sections = $this->sections->findByEventId($event->getId(), new QueryParamsDTO(per_page: 100))->items();
        $ticketTitles = $this->products->findWhere(['event_id' => $event->getId()])
            ->mapWithKeys(fn(ProductDomainObject $p): array => [$p->getId() => $this->clip($p->getTitle())])
            ->all();

        $list = array_map(fn(SeatingSectionDomainObject $s): array => [
            'id' => $s->getId(),
            'name' => $this->clip($s->getName()),
            'ticket_id' => $s->getProductId(),
            'ticket' => $ticketTitles[$s->getProductId()] ?? null,
            'rows' => $s->getRowCount(),
            'seats_per_row' => $s->getSeatsPerRow(),
            'total_seats' => $s->getRowCount() * $s->getSeatsPerRow(),
            'status' => $s->getStatus(),
        ], $sections);

        return $this->toJson([
            'event_id' => $event->getId(),
            'event_title' => $this->clip($event->getTitle()),
            'has_seat_map' => $list !== [],
            'total_seats' => array_sum(array_column($list, 'total_seats')),
            'sections' => $list,
        ]);
    }
}
