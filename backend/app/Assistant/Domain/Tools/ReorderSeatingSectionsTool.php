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
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\NumberSchema;
use Psr\Log\LoggerInterface;

/**
 * "La VIP va adelante", "los laterales a los costados de la platea": where each
 * sector sits on the plan, as rows from the stage back and, inside a row, left
 * to right. Writes the same `order` and canvas positions the seating designer
 * uses, so the panel, the public map and the studio preview all agree.
 * Layout only - no seats or sales are touched, so a plain confirmation is enough.
 */
class ReorderSeatingSectionsTool extends AbstractAssistantWriteTool
{
    public const ROW_HEIGHT = 240;
    public const COLUMN_WIDTH = 320;

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
            ->for('Arranges the seat map: `rows` lists the sections from the stage backwards, and each row '
                . 'lists its section ids from left to right, so [[vip], [lateral_izq, platea, lateral_der]] '
                . 'puts VIP in front and the two laterals beside Platea. Sections not mentioned go behind. '
                . 'Ids from get_seating_sections. Layout only, no seats change. Preview without confirm; '
                . 'confirm=true applies it.')
            ->withNumberParameter('event_id', 'The event.')
            ->withArrayParameter(
                'rows',
                'Rows from the stage back; each row is the list of section ids left to right.',
                new ArraySchema('row', 'section ids left to right', new NumberSchema('id', 'section id')),
            )
            ->withBooleanParameter('confirm', 'Pass true only after the organizer confirmed.', required: false);
    }

    public function __invoke(int|float $event_id, array $rows, ?bool $confirm = null): string
    {
        $args = $this->validateArguments(
            compact('event_id', 'rows'),
            [
                'event_id' => 'required|integer|min:1',
                'rows' => 'required|array|min:1|max:30',
                'rows.*' => 'array|min:1|max:6',
                'rows.*.*' => 'integer|min:1',
            ],
        );

        $event = $this->authorizeEvent((int)$args['event_id']);

        /** @var SeatingSectionDomainObject[] $existing */
        $existing = $this->sections->findByEventId($event->getId(), new QueryParamsDTO(per_page: 100))->items();
        $byId = [];
        foreach ($existing as $section) {
            $byId[$section->getId()] = $section;
        }

        $seen = [];
        $layout = [];
        foreach ($args['rows'] as $row) {
            $ids = [];
            foreach ($row as $id) {
                $id = (int)$id;
                if (!isset($byId[$id])) {
                    return $this->toJson(['error' => 'section_not_found', 'details' => 'Unknown section id: ' . $id]);
                }
                if (!in_array($id, $seen, true)) {
                    $ids[] = $id;
                    $seen[] = $id;
                }
            }
            if ($ids !== []) {
                $layout[] = $ids;
            }
        }

        // Sections left out keep going, one per row, behind the ones named.
        foreach ($byId as $id => $section) {
            if (!in_array($id, $seen, true)) {
                $layout[] = [$id];
            }
        }

        $plan = array_map(fn(array $ids, int $rowIndex): array => [
            'row_from_stage' => $rowIndex + 1,
            'left_to_right' => array_map(fn(int $id): string => $this->clip($byId[$id]->getName()), $ids),
        ], $layout, array_keys($layout));

        if ($confirm !== true) {
            return $this->toJson([
                'status' => 'needs_confirmation',
                'would_apply' => ['rows_from_stage' => $plan],
                'hint' => 'Describe the layout in words (row by row, left to right) and ask for confirmation; call again with confirm=true once they agree.',
            ]);
        }

        $order = 0;
        foreach ($layout as $rowIndex => $ids) {
            $count = count($ids);
            foreach ($ids as $column => $id) {
                // Centre each row on the canvas: a lone section sits at x=0 like the designer creates it.
                $x = (int)round(($column - ($count - 1) / 2) * self::COLUMN_WIDTH);
                $this->sections->updateFromArray($id, [
                    SeatingSectionDomainObjectAbstract::ORDER => $order++,
                    SeatingSectionDomainObjectAbstract::POSITION_X => $x,
                    SeatingSectionDomainObjectAbstract::POSITION_Y => $rowIndex * self::ROW_HEIGHT,
                ]);
            }
        }

        $this->logWrite('seating_sections_reordered', ['event_id' => $event->getId(), 'rows' => $layout]);

        return $this->toJson([
            'status' => 'applied',
            'rows_from_stage' => $plan,
            'next_steps' => 'The map now shows this layout. Ask if anything else should move.',
        ]);
    }
}
