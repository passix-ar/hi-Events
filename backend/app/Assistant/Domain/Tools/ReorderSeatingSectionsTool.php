<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Domain\Tools;

use HiEvents\Assistant\Domain\AssistantContext;
use HiEvents\DomainObjects\Generated\SeatingSectionDomainObjectAbstract;
use HiEvents\DomainObjects\SeatingSectionDomainObject;
use HiEvents\Http\DTO\QueryParamsDTO;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\SeatingSectionRepositoryInterface;
use HiEvents\Services\Infrastructure\Authorization\IsAuthorizedService;
use Prism\Prism\Schema\NumberSchema;
use Psr\Log\LoggerInterface;

/**
 * "La VIP va adelante de la platea": the order of the sectors from the stage
 * back. Writes the same `order` and stacked position_y the seating designer
 * uses, so the panel, the public map and the studio preview all agree.
 * Layout only - no seats or sales are touched, so a plain confirmation is enough.
 */
class ReorderSeatingSectionsTool extends AbstractAssistantWriteTool
{
    private const ROW_HEIGHT = 240;

    public function __construct(
        AssistantContext                                   $context,
        IsAuthorizedService                                $isAuthorizedService,
        EventRepositoryInterface                           $events,
        LoggerInterface                                    $logger,
        private readonly SeatingSectionRepositoryInterface $sections,
    )
    {
        parent::__construct($context, $isAuthorizedService, $events, $logger);
    }

    protected function configure(): void
    {
        $this
            ->as('reorder_seating_sections')
            ->for('Sets the order of the seat map sections from the stage backwards ("VIP in front of '
                . 'Platea"). Pass every section id of the event, front first (get_seating_sections has '
                . 'the ids). Layout only: no seats change. Preview without confirm; confirm=true applies it.')
            ->withNumberParameter('event_id', 'The event.')
            ->withArrayParameter('section_ids_front_to_back', 'All section ids, closest to the stage first.', new NumberSchema('id', 'section id'))
            ->withBooleanParameter('confirm', 'Pass true only after the organizer confirmed.', required: false);
    }

    public function __invoke(int|float $event_id, array $section_ids_front_to_back, ?bool $confirm = null): string
    {
        $args = $this->validateArguments(
            compact('event_id', 'section_ids_front_to_back'),
            [
                'event_id' => 'required|integer|min:1',
                'section_ids_front_to_back' => 'required|array|min:1|max:50',
                'section_ids_front_to_back.*' => 'integer|min:1',
            ],
        );

        $event = $this->authorizeEvent((int)$args['event_id']);

        /** @var SeatingSectionDomainObject[] $existing */
        $existing = $this->sections->findByEventId($event->getId(), new QueryParamsDTO(per_page: 100))->items();
        $byId = [];
        foreach ($existing as $section) {
            $byId[$section->getId()] = $section;
        }

        $wanted = array_values(array_unique(array_map('intval', $args['section_ids_front_to_back'])));
        $unknown = array_diff($wanted, array_keys($byId));
        $missing = array_diff(array_keys($byId), $wanted);

        if ($unknown !== []) {
            return $this->toJson(['error' => 'section_not_found', 'details' => 'Unknown section ids: ' . implode(', ', $unknown)]);
        }

        // Sections left out keep their relative order behind the ones named.
        $ordered = [...$wanted, ...array_keys(array_filter($byId, fn(SeatingSectionDomainObject $s): bool => in_array($s->getId(), $missing, true)))];

        $plan = array_map(fn(int $id, int $index): array => [
            'position' => $index + 1,
            'id' => $id,
            'name' => $this->clip($byId[$id]->getName()),
        ], $ordered, array_keys($ordered));

        if ($confirm !== true) {
            return $this->toJson([
                'status' => 'needs_confirmation',
                'would_apply' => ['order_from_stage' => $plan],
                'hint' => 'Describe the new order in words ("1. VIP, 2. Platea") and ask for confirmation; call again with confirm=true once they agree.',
            ]);
        }

        foreach ($ordered as $index => $id) {
            $this->sections->updateFromArray($id, [
                SeatingSectionDomainObjectAbstract::ORDER => $index,
                SeatingSectionDomainObjectAbstract::POSITION_Y => $index * self::ROW_HEIGHT,
            ]);
        }

        $this->logWrite('seating_sections_reordered', ['event_id' => $event->getId(), 'order' => $ordered]);

        return $this->toJson([
            'status' => 'applied',
            'order_from_stage' => $plan,
            'next_steps' => 'The map now shows the sections in this order. Ask if anything else should move.',
        ]);
    }
}
