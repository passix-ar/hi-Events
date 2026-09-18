<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Domain\Tools;

use HiEvents\Assistant\Domain\AssistantContext;
use HiEvents\Assistant\Exceptions\AssistantToolArgumentException;
use HiEvents\DataTransferObjects\AddressDTO;
use HiEvents\DomainObjects\Enums\EventCategory;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Services\Application\Handlers\Event\DTO\UpdateEventDTO;
use HiEvents\Services\Application\Handlers\Event\UpdateEventHandler;
use HiEvents\Services\Infrastructure\Authorization\IsAuthorizedService;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

class UpdateEventTool extends AbstractAssistantWriteTool
{
    public const CONFIRMATION_PHRASE = 'MODIFICAR';

    public function __construct(
        AssistantContext                    $context,
        IsAuthorizedService                 $isAuthorizedService,
        EventRepositoryInterface            $events,
        LoggerInterface                     $logger,
        private readonly UpdateEventHandler $updateEvent,
    )
    {
        parent::__construct($context, $isAuthorizedService, $events, $logger);
    }

    protected function configure(): void
    {
        $this
            ->as('update_event')
            ->for('Changes an existing event: title, start/end date, description or category. Only the fields you '
                . 'pass change. Preview without confirm first. On a DRAFT a plain yes is enough; on a PUBLISHED event '
                . 'buyers already hold tickets, so the organizer must reply with the exact word MODIFICAR, passed '
                . 'as confirmation_phrase. Dates are "YYYY-MM-DD HH:MM" in the organizer timezone.')
            ->withNumberParameter('event_id', 'The event.')
            ->withStringParameter('title', 'New title (max 150).', required: false)
            ->withStringParameter('start_date', 'New start, "YYYY-MM-DD HH:MM".', required: false)
            ->withStringParameter('end_date', 'New end, "YYYY-MM-DD HH:MM".', required: false)
            ->withStringParameter('description', 'New description.', required: false)
            ->withEnumParameter('category', 'New category.', EventCategory::valuesArray(), required: false)
            ->withBooleanParameter('confirm', 'True only after the organizer confirmed the preview.', required: false)
            ->withStringParameter('confirmation_phrase', 'The exact word the organizer typed (MODIFICAR) for a published event.', required: false);
    }

    public function __invoke(
        int|float $event_id,
        ?string   $title = null,
        ?string   $start_date = null,
        ?string   $end_date = null,
        ?string   $description = null,
        ?string   $category = null,
        ?bool     $confirm = null,
        ?string   $confirmation_phrase = null,
    ): string
    {
        $args = $this->validateArguments(
            compact('event_id', 'title', 'start_date', 'end_date', 'description', 'category'),
            [
                'event_id' => 'required|integer|min:1',
                'title' => 'nullable|string|min:1|max:150',
                'start_date' => 'nullable|string',
                'end_date' => 'nullable|string',
                'description' => 'nullable|string|max:5000',
                'category' => 'nullable|in:' . implode(',', EventCategory::valuesArray()),
            ],
        );

        $event = $this->authorizeEvent((int)$args['event_id']);
        $tz = $event->getTimezone() ?? $this->context->timezone;

        $currentStart = $this->local($event->getStartDate(), $tz);
        $currentEnd = $this->local($event->getEndDate(), $tz);

        $newStart = $args['start_date'] === null ? $currentStart : $this->parse($args['start_date'], $tz, 'start_date');
        $newEnd = $args['end_date'] === null ? $currentEnd : $this->parse($args['end_date'], $tz, 'end_date');

        // "Move it to the 27th" means the whole event moves: when only the start
        // changes, the end keeps its distance from it instead of being left behind.
        $endShifted = false;
        if ($args['start_date'] !== null && $args['end_date'] === null && $currentStart !== null && $currentEnd !== null) {
            $newEnd = $currentEnd->copy()->addSeconds($currentStart->diffInSeconds($newStart, false));
            $endShifted = true;
        }

        if ($newStart !== null && $newEnd !== null && $newEnd->lessThan($newStart)) {
            return $this->toJson(['error' => 'invalid_arguments', 'details' => 'end_date must be after start_date.']);
        }

        $changes = array_filter([
            'title' => $args['title'],
            'start_date' => $args['start_date'] === null ? null : $newStart?->format('Y-m-d H:i'),
            'end_date' => ($args['end_date'] === null && !$endShifted) ? null : $newEnd?->format('Y-m-d H:i'),
            'description' => $args['description'],
            'category' => $args['category'],
        ], static fn($v) => $v !== null);

        if ($changes === []) {
            return $this->toJson(['error' => 'invalid_arguments', 'details' => 'Pass at least one field to change.']);
        }

        $isLive = $event->getStatus() === EventStatus::LIVE->name;

        $payload = [
            'event' => ['id' => $event->getId(), 'title' => $this->clip($event->getTitle()), 'status' => $event->getStatus()],
            'current' => ['start_date' => $currentStart?->format('Y-m-d H:i'), 'end_date' => $currentEnd?->format('Y-m-d H:i'), 'category' => $event->getCategory()],
            'changes' => $changes,
            'note' => $endShifted ? 'end_date moved by the same amount as start_date to keep the duration.' : null,
            'double_check' => $isLive
                ? 'The event is published and people hold tickets: ask the organizer to reply with the exact word ' . self::CONFIRMATION_PHRASE . '.'
                : 'Draft event: a plain confirmation is enough.',
        ];

        if ($confirm !== true) {
            return $this->preview($payload);
        }

        if (($blocked = $this->doubleCheck($isLive, $confirm, $confirmation_phrase, self::CONFIRMATION_PHRASE)) !== null) {
            return $blocked;
        }

        $location = $event->getLocationDetails();

        $updated = $this->updateEvent->handle(new UpdateEventDTO(
            title: $changes['title'] ?? $event->getTitle(),
            category: EventCategory::tryFrom($changes['category'] ?? (string)$event->getCategory()),
            account_id: $this->context->accountId,
            id: $event->getId(),
            start_date: $newStart?->format('Y-m-d H:i:s'),
            end_date: $newEnd?->format('Y-m-d H:i:s'),
            description: array_key_exists('description', $changes) ? $changes['description'] : $event->getDescription(),
            timezone: $tz,
            currency: $event->getCurrency(),
            location: $event->getLocation(),
            location_details: is_array($location) ? AddressDTO::from($location) : null,
            status: $event->getStatus(),
        ));

        $this->logWrite('event_updated', ['event_id' => $event->getId(), 'changes' => $changes]);

        return $this->toJson([
            'status' => 'updated',
            'event' => ['id' => $updated->getId(), 'title' => $this->clip($updated->getTitle())],
            'applied' => $changes,
        ]);
    }

    private function local(?string $storedUtc, string $tz): ?Carbon
    {
        return $storedUtc === null ? null : Carbon::parse($storedUtc, 'UTC')->setTimezone($tz);
    }

    private function parse(string $value, string $tz, string $field): Carbon
    {
        foreach (['Y-m-d H:i', 'Y-m-d H:i:s', 'Y-m-d'] as $format) {
            if (Carbon::canBeCreatedFromFormat($value, $format)) {
                $date = Carbon::createFromFormat($format, $value, $tz);
                return $format === 'Y-m-d' ? $date->startOfDay() : $date;
            }
        }

        throw new AssistantToolArgumentException(sprintf('%s must be "YYYY-MM-DD HH:MM" or "YYYY-MM-DD".', $field));
    }
}
