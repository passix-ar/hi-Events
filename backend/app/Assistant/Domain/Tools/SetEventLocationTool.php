<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Domain\Tools;

use HiEvents\Assistant\Domain\AssistantContext;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Services\Application\Handlers\EventSettings\DTO\PartialUpdateEventSettingsDTO;
use HiEvents\Services\Application\Handlers\EventSettings\PartialUpdateEventSettingsHandler;
use HiEvents\Services\Infrastructure\Authorization\IsAuthorizedService;
use HiEvents\Services\Infrastructure\HtmlPurifier\HtmlPurifierService;
use Psr\Log\LoggerInterface;

/**
 * Where the event happens, the way the page and the map need it: venue plus a
 * real street address (the panel requires street, city, postcode and country),
 * or an online event with the access link buyers get after paying.
 */
class SetEventLocationTool extends AbstractAssistantWriteTool
{
    public function __construct(
        AssistantContext                                   $context,
        IsAuthorizedService                                $isAuthorizedService,
        EventRepositoryInterface                           $events,
        LoggerInterface                                    $logger,
        private readonly EventSettingsRepositoryInterface  $eventSettings,
        private readonly PartialUpdateEventSettingsHandler $updateSettings,
        private readonly HtmlPurifierService               $purifier,
    )
    {
        parent::__construct($context, $isAuthorizedService, $events, $logger);
    }

    protected function configure(): void
    {
        $this
            ->as('set_event_location')
            ->for('Sets where the event happens. In-person: venue_name, street address (address_line_1), city, '
                . 'postcode and country (default AR), optional state and a Google Maps link. Online: online=true '
                . 'plus access_details (link/instructions buyers receive after paying). Ask the organizer for the '
                . 'street address if create_draft_event only had venue and city; never invent one. Preview '
                . 'without confirm; confirm=true applies; MODIFICAR on a published event.')
            ->withNumberParameter('event_id', 'The event.')
            ->withBooleanParameter('online', 'true for an online event (no physical address).', required: false)
            ->withStringParameter('access_details', 'Online only: link and instructions buyers get after paying.', required: false)
            ->withStringParameter('venue_name', 'Venue name, e.g. "Club Aurora".', required: false)
            ->withStringParameter('address_line_1', 'Street and number.', required: false)
            ->withStringParameter('address_line_2', 'Floor, unit, extra reference.', required: false)
            ->withStringParameter('city', 'City.', required: false)
            ->withStringParameter('state_or_region', 'Province / state.', required: false)
            ->withStringParameter('postcode', 'Postal code.', required: false)
            ->withStringParameter('country', 'ISO-2 country code, default AR.', required: false)
            ->withStringParameter('maps_url', 'Google Maps link, optional.', required: false)
            ->withBooleanParameter('confirm', 'Pass true only after the organizer confirmed the preview.', required: false)
            ->withStringParameter('confirmation_phrase', 'The exact word MODIFICAR typed by the organizer, only for published events.', required: false);
    }

    public function __invoke(
        int|float $event_id,
        ?bool     $online = null,
        ?string   $access_details = null,
        ?string   $venue_name = null,
        ?string   $address_line_1 = null,
        ?string   $address_line_2 = null,
        ?string   $city = null,
        ?string   $state_or_region = null,
        ?string   $postcode = null,
        ?string   $country = null,
        ?string   $maps_url = null,
        ?bool     $confirm = null,
        ?string   $confirmation_phrase = null,
    ): string
    {
        $args = $this->validateArguments(
            compact('event_id', 'online', 'access_details', 'venue_name', 'address_line_1', 'address_line_2', 'city', 'state_or_region', 'postcode', 'country', 'maps_url'),
            [
                'event_id' => 'required|integer|min:1',
                'online' => 'nullable|boolean',
                'access_details' => 'nullable|string|max:3000',
                'venue_name' => 'nullable|string|max:255',
                'address_line_1' => 'nullable|string|max:255',
                'address_line_2' => 'nullable|string|max:255',
                'city' => 'nullable|string|max:85',
                'state_or_region' => 'nullable|string|max:85',
                'postcode' => 'nullable|string|max:85',
                'country' => 'nullable|string|size:2',
                'maps_url' => 'nullable|url|max:500',
            ],
        );

        $event = $this->authorizeEvent((int)$args['event_id']);
        $isLive = $event->getStatus() === EventStatus::LIVE->name;

        /** @var EventSettingDomainObject|null $settings */
        $settings = $this->eventSettings->findFirstWhere(['event_id' => $event->getId()]);
        $current = array_filter(is_array($settings?->getLocationDetails()) ? $settings->getLocationDetails() : [], static fn($v): bool => $v !== null && $v !== '');
        if ($current === [] && is_array($event->getLocationDetails())) {
            $current = array_filter($event->getLocationDetails(), static fn($v): bool => $v !== null && $v !== '');
        }

        if ($args['online'] === true) {
            $changes = [
                'is_online_event' => true,
                'online_event_connection_details' => $args['access_details'] !== null && trim($args['access_details']) !== ''
                    ? $this->purifier->purify(nl2br(e(trim($args['access_details']))))
                    : $settings?->getOnlineEventConnectionDetails(),
            ];
            $shown = ['online' => true, 'access_details' => $args['access_details'] ?? '(unchanged)'];
        } else {
            $address = [
                'venue_name' => $args['venue_name'] ?? ($current['venue_name'] ?? null),
                'address_line_1' => $args['address_line_1'] ?? ($current['address_line_1'] ?? null),
                'address_line_2' => $args['address_line_2'] ?? ($current['address_line_2'] ?? null),
                'city' => $args['city'] ?? ($current['city'] ?? null),
                'state_or_region' => $args['state_or_region'] ?? ($current['state_or_region'] ?? null),
                'zip_or_postal_code' => $args['postcode'] ?? ($current['zip_or_postal_code'] ?? null),
                'country' => strtoupper($args['country'] ?? ($current['country'] ?? 'AR')),
            ];

            $missing = array_keys(array_filter(
                ['address_line_1' => $address['address_line_1'], 'city' => $address['city'], 'zip_or_postal_code' => $address['zip_or_postal_code']],
                static fn($v): bool => $v === null || trim((string)$v) === '',
            ));
            if ($missing !== []) {
                return $this->toJson([
                    'error' => 'address_incomplete',
                    'details' => 'The map needs street address, city and postcode. Missing: ' . implode(', ', $missing) . '. Ask the organizer; do not invent them.',
                    'current' => $current,
                ]);
            }

            $changes = ['is_online_event' => false, 'location_details' => $address];
            if ($args['maps_url'] !== null) {
                $changes['maps_url'] = $args['maps_url'];
            }
            $shown = ['online' => false, 'current' => $current, 'new' => $address, 'maps_url' => $args['maps_url']];
        }

        $payload = ['event_id' => $event->getId(), 'event_title' => $this->clip($event->getTitle()), 'event_status' => $event->getStatus(), 'location' => $shown];

        if ($confirm !== true) {
            return $this->toJson([
                'status' => 'needs_confirmation',
                ...$payload,
                'hint' => $isLive
                    ? 'This event is published: show the new address and ask the organizer to reply with the exact word MODIFICAR before calling again with confirm=true and confirmation_phrase.'
                    : 'Show the address as the page will display it and ask for confirmation; call again with confirm=true once they agree.',
            ]);
        }

        if (($refusal = $this->doubleCheck($isLive, $confirm, $confirmation_phrase, 'MODIFICAR')) !== null) {
            return $refusal;
        }

        $this->updateSettings->handle(new PartialUpdateEventSettingsDTO(
            account_id: $this->context->accountId,
            event_id: $event->getId(),
            settings: $changes,
        ));

        $this->logWrite('location_set', ['event_id' => $event->getId(), 'online' => $args['online'] === true]);

        return $this->toJson(['status' => 'applied', ...$payload, 'next_steps' => 'The page now shows the location (and the map when there is an address).']);
    }
}
