<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Domain\Tools;

use HiEvents\Assistant\Domain\AssistantContext;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\EventDomainObjectAbstract;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\Http\DTO\QueryParamsDTO;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Infrastructure\Authorization\IsAuthorizedService;
use Illuminate\Database\Eloquent\Builder;
use Psr\Log\LoggerInterface;

class FindEventsTool extends AbstractAssistantTool
{
    private const MAX_LIMIT = 10;

    public function __construct(
        AssistantContext                          $context,
        IsAuthorizedService                       $isAuthorizedService,
        private readonly EventRepositoryInterface $events,
        LoggerInterface                           $logger,
    )
    {
        parent::__construct($context, $isAuthorizedService, $events, $logger);
    }

    protected function configure(): void
    {
        $this
            ->as('find_events')
            ->for('Lists the organizer\'s events so you can resolve an event name to its event_id. '
                . 'Use it before any tool that needs an event_id when the user refers to an event by name. '
                . 'Returns id, title, dates, status and currency.')
            ->withStringParameter('query', 'Optional text to match against the event title (case-insensitive).', required: false)
            ->withEnumParameter('period', 'Which events to list: upcoming (default), past or all.', ['upcoming', 'past', 'all'], required: false)
            ->withNumberParameter('limit', 'Maximum number of events to return (1-10, default 10).', required: false);
    }

    public function __invoke(?string $query = null, ?string $period = null, int|float|null $limit = null): string
    {
        $args = $this->validateArguments(
            ['query' => $query, 'period' => $period, 'limit' => $limit],
            [
                'query' => 'nullable|string|max:100',
                'period' => 'nullable|in:upcoming,past,all',
                'limit' => 'nullable|integer|min:1|max:' . self::MAX_LIMIT,
            ],
        );

        $period = $args['period'] ?? 'upcoming';

        $where = [
            [EventDomainObjectAbstract::ACCOUNT_ID, '=', $this->context->accountId],
            [EventDomainObjectAbstract::ORGANIZER_ID, '=', $this->context->organizerId],
        ];

        if ($period === 'upcoming') {
            $where[] = static function (Builder $builder): void {
                $builder
                    ->where(EventDomainObjectAbstract::STATUS, '!=', EventStatus::ARCHIVED->name)
                    ->where(static function (Builder $q): void {
                        $q->whereNull(EventDomainObjectAbstract::END_DATE)
                            ->orWhere(EventDomainObjectAbstract::END_DATE, '>=', now());
                    });
            };
        }

        if ($period === 'past') {
            $where[] = [EventDomainObjectAbstract::END_DATE, '<', now()];
        }

        $events = $this->events->findEvents(
            where: $where,
            params: QueryParamsDTO::fromArray([
                'query' => $args['query'] ?? null,
                'per_page' => (int)($args['limit'] ?? self::MAX_LIMIT),
                'page' => 1,
                'sort_by' => EventDomainObjectAbstract::START_DATE,
                'sort_direction' => $period === 'past' ? 'desc' : 'asc',
            ]),
        );

        return $this->toJson([
            'total' => $events->total(),
            'events' => collect($events->items())->map(fn(EventDomainObject $event): array => [
                'id' => $event->getId(),
                'title' => $this->clip($event->getTitle()),
                'status' => $event->getStatus(),
                'start_date' => $event->getStartDate(),
                'end_date' => $event->getEndDate(),
                'currency' => $event->getCurrency(),
            ])->values()->all(),
        ]);
    }
}
