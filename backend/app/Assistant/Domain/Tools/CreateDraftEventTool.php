<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Domain\Tools;

use HiEvents\Assistant\Domain\AssistantContext;
use HiEvents\Assistant\Exceptions\AssistantToolArgumentException;
use HiEvents\DataTransferObjects\AddressDTO;
use HiEvents\DomainObjects\Enums\EventCategory;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\Generated\EventDomainObjectAbstract;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Application\Handlers\Event\CreateEventHandler;
use HiEvents\Services\Application\Handlers\Event\DTO\CreateEventDTO;
use HiEvents\Services\Infrastructure\Authorization\IsAuthorizedService;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

class CreateDraftEventTool extends AbstractAssistantWriteTool
{
    public function __construct(
        AssistantContext                     $context,
        IsAuthorizedService                  $isAuthorizedService,
        private readonly EventRepositoryInterface $events,
        LoggerInterface                      $logger,
        private readonly CreateEventHandler  $createEvent,
    )
    {
        parent::__construct($context, $isAuthorizedService, $events, $logger);
    }

    protected function configure(): void
    {
        $this
            ->as('create_draft_event')
            ->for('Creates a new event for this organizer as a DRAFT: it is not visible to anybody and '
                . 'sells nothing until the organizer publishes it from the panel. '
                . 'Call it first without confirm to get a preview, show that to the organizer, and only call '
                . 'it again with confirm=true once they agree. Dates are in the organizer timezone. '
                . 'After creating the event, use create_ticket to add ticket types.')
            ->withStringParameter('title', 'Event title as the organizer would write it (max 150 characters).')
            ->withStringParameter('start_date', 'When it starts: "YYYY-MM-DD HH:MM", or "YYYY-MM-DD" for an all-day date.')
            ->withStringParameter('end_date', 'When it ends, same format. Omit if the organizer did not say.', required: false)
            ->withStringParameter('description', 'Short description for the event page.', required: false)
            ->withEnumParameter('category', 'Event category.', EventCategory::valuesArray(), required: false)
            ->withStringParameter('venue_name', 'Name of the venue.', required: false)
            ->withStringParameter('city', 'City of the venue.', required: false)
            ->withBooleanParameter('confirm', 'Pass true only after the organizer confirmed the preview.', required: false);
    }

    public function __invoke(
        string       $title,
        string       $start_date,
        ?string      $end_date = null,
        ?string      $description = null,
        ?string      $category = null,
        ?string      $venue_name = null,
        ?string      $city = null,
        ?bool        $confirm = null,
    ): string
    {
        $args = $this->validateArguments(
            [
                'title' => $title,
                'start_date' => $start_date,
                'end_date' => $end_date,
                'description' => $description,
                'category' => $category,
                'venue_name' => $venue_name,
                'city' => $city,
            ],
            [
                'title' => 'required|string|min:1|max:150',
                'start_date' => 'required|string',
                'end_date' => 'nullable|string',
                'description' => 'nullable|string|max:5000',
                'category' => 'nullable|in:' . implode(',', EventCategory::valuesArray()),
                'venue_name' => 'nullable|string|max:100',
                'city' => 'nullable|string|max:85',
            ],
        );

        $startDate = $this->parseDate($args['start_date'], 'start_date');
        $endDate = $args['end_date'] === null ? null : $this->parseDate($args['end_date'], 'end_date');

        if ($endDate !== null && $endDate->lessThan($startDate)) {
            return $this->toJson(['error' => 'invalid_arguments', 'details' => 'end_date must be after start_date.']);
        }

        $payload = [
            'title' => $args['title'],
            'start_date' => $startDate->format('Y-m-d H:i'),
            'end_date' => $endDate?->format('Y-m-d H:i'),
            'timezone' => $this->context->timezone,
            'currency' => $this->context->currency,
            'category' => $args['category'] ?? EventCategory::OTHER->value,
            'venue_name' => $args['venue_name'],
            'city' => $args['city'],
            'status' => EventStatus::DRAFT->name,
        ];

        // Asking twice for the same event is a retry, not a second event. The day
        // window has to be built in UTC because that is how the dates are stored
        // (CreateEventService runs them through DateHelper::convertToUTC).
        $existing = $this->findExisting($args['title'], $startDate);

        if ($existing !== null) {
            return $this->toJson([
                'status' => 'already_exists',
                'event' => $this->describe($existing),
                'hint' => 'An event with this title and start date already exists; nothing was created.',
            ]);
        }

        if ($confirm !== true) {
            return $this->preview($payload);
        }

        $event = $this->createEvent->handle(CreateEventDTO::fromArray([
            'title' => $args['title'],
            'organizer_id' => $this->context->organizerId,
            'account_id' => $this->context->accountId,
            'user_id' => $this->context->user->getId(),
            'start_date' => $startDate->format('Y-m-d H:i:s'),
            'end_date' => $endDate?->format('Y-m-d H:i:s'),
            'description' => $args['description'],
            'timezone' => $this->context->timezone,
            'currency' => $this->context->currency,
            'category' => $args['category'] ?? EventCategory::OTHER->value,
            'status' => EventStatus::DRAFT->name,
            'location_details' => $this->locationDetails($args),
        ]));

        $this->logWrite('event_created', [
            'event_id' => $event->getId(),
            'title' => $event->getTitle(),
        ]);

        return $this->toJson([
            'status' => 'created',
            'event' => $this->describe($event),
            'next_steps' => 'The event is a draft: add ticket types with create_ticket, then the organizer '
                . 'publishes it from the panel. Nobody can see or buy it until they do.',
        ]);
    }

    private function findExisting(string $title, Carbon $startDate): ?EventDomainObject
    {
        /** @var EventDomainObject|null $event */
        $event = $this->events->findFirstWhere([
            EventDomainObjectAbstract::ACCOUNT_ID => $this->context->accountId,
            EventDomainObjectAbstract::ORGANIZER_ID => $this->context->organizerId,
            EventDomainObjectAbstract::TITLE => $title,
            [EventDomainObjectAbstract::START_DATE, '>=', $startDate->copy()->startOfDay()->setTimezone('UTC')->format('Y-m-d H:i:s')],
            [EventDomainObjectAbstract::START_DATE, '<=', $startDate->copy()->endOfDay()->setTimezone('UTC')->format('Y-m-d H:i:s')],
        ]);

        return $event;
    }

    /**
     * AddressDTO is a Spatie Data object, and BaseDTO::fromArray only hydrates
     * nested BaseDTOs, so passing an array here is a TypeError. Only the fields
     * the organizer actually gave are set; they complete the address in the panel.
     */
    private function locationDetails(array $args): ?AddressDTO
    {
        if (empty($args['venue_name']) && empty($args['city'])) {
            return null;
        }

        return new AddressDTO(
            venue_name: $args['venue_name'],
            city: $args['city'],
        );
    }

    private function describe(EventDomainObject $event): array
    {
        return [
            'id' => $event->getId(),
            'title' => $this->clip($event->getTitle()),
            'status' => $event->getStatus(),
            'start_date' => $event->getStartDate(),
            'end_date' => $event->getEndDate(),
            'currency' => $event->getCurrency(),
        ];
    }

    private function parseDate(string $value, string $field): Carbon
    {
        foreach (['Y-m-d H:i', 'Y-m-d H:i:s', 'Y-m-d'] as $format) {
            $parsed = Carbon::canBeCreatedFromFormat($value, $format)
                ? Carbon::createFromFormat($format, $value, $this->context->timezone)
                : null;

            if ($parsed !== null) {
                return $format === 'Y-m-d' ? $parsed->startOfDay() : $parsed;
            }
        }

        throw new AssistantToolArgumentException(
            sprintf('%s must be "YYYY-MM-DD HH:MM" or "YYYY-MM-DD".', $field)
        );
    }
}
