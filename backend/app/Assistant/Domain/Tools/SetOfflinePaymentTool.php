<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Domain\Tools;

use HiEvents\Assistant\Domain\AssistantContext;
use HiEvents\DomainObjects\Enums\PaymentProviders;
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
 * "Cobro por transferencia": turns offline payment on for an event with the
 * instructions the buyer will read (alias, CBU, WhatsApp...), or turns it off.
 * The same setting the panel exposes under event settings -> payment; the
 * organizer still confirms each order by hand from the orders page.
 */
class SetOfflinePaymentTool extends AbstractAssistantWriteTool
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
            ->as('set_offline_payment')
            ->for('Enables or disables offline payment (bank transfer, cash) for one event. When enabling, '
                . '`instructions` is the text the buyer sees at checkout: ask the organizer for their alias/CBU, '
                . 'who to send the receipt to, and any deadline - never invent bank details. Orders stay '
                . '"awaiting payment" until the organizer marks them paid in the panel. Call without confirm '
                . 'for a preview; confirm=true applies it. On a published event the organizer must reply '
                . 'MODIFICAR (pass it as confirmation_phrase).')
            ->withNumberParameter('event_id', 'The event.')
            ->withBooleanParameter('enabled', 'true to enable offline payment, false to disable it.')
            ->withStringParameter('instructions', 'What the buyer must do to pay, in the organizer\'s words (required when enabling). Plain text; line breaks allowed.', required: false)
            ->withBooleanParameter('confirm', 'Pass true only after the organizer confirmed the preview.', required: false)
            ->withStringParameter('confirmation_phrase', 'The exact word MODIFICAR typed by the organizer, only for published events.', required: false);
    }

    public function __invoke(
        int|float $event_id,
        bool      $enabled,
        ?string   $instructions = null,
        ?bool     $confirm = null,
        ?string   $confirmation_phrase = null,
    ): string
    {
        $args = $this->validateArguments(
            compact('event_id', 'enabled', 'instructions'),
            [
                'event_id' => 'required|integer|min:1',
                'enabled' => 'required|boolean',
                'instructions' => 'nullable|string|max:2000',
            ],
        );

        $event = $this->authorizeEvent((int)$args['event_id']);
        $isLive = $event->getStatus() === EventStatus::LIVE->name;

        /** @var EventSettingDomainObject|null $settings */
        $settings = $this->eventSettings->findFirstWhere(['event_id' => $event->getId()]);
        $providers = is_array($settings?->getPaymentProviders()) ? $settings->getPaymentProviders() : [];
        $currentlyEnabled = in_array(PaymentProviders::OFFLINE->value, $providers, true);

        $text = trim((string)($args['instructions'] ?? ''));

        if ($args['enabled'] && $text === '' && trim((string)$settings?->getOfflinePaymentInstructions()) === '') {
            return $this->toJson([
                'error' => 'instructions_required',
                'details' => 'Offline payment needs instructions for the buyer (how to transfer, alias/CBU, where to send the receipt). Ask the organizer and call again.',
            ]);
        }

        $newProviders = $args['enabled']
            ? array_values(array_unique([...$providers, PaymentProviders::OFFLINE->value]))
            : array_values(array_diff($providers, [PaymentProviders::OFFLINE->value]));

        $newInstructions = $args['enabled'] && $text !== ''
            ? $this->purifier->purify(nl2br(e($text)))
            : $settings?->getOfflinePaymentInstructions();

        $payload = [
            'event_id' => $event->getId(),
            'event_title' => $this->clip($event->getTitle()),
            'event_status' => $event->getStatus(),
            'offline_payment' => ['currently' => $currentlyEnabled, 'would_be' => (bool)$args['enabled']],
            'instructions' => $args['enabled'] ? strip_tags(str_replace('<br />', "\n", (string)$newInstructions)) : null,
            'payment_providers' => $newProviders,
            'note' => 'Orders paid offline wait as "awaiting payment" until the organizer marks them paid in Orders; tickets are issued then.',
        ];

        if ($confirm !== true) {
            return $this->toJson([
                'status' => 'needs_confirmation',
                ...$payload,
                'hint' => $isLive
                    ? 'This event is published: show the change and ask the organizer to reply with the exact word MODIFICAR before calling again with confirm=true and confirmation_phrase.'
                    : 'Show the instructions the buyer will read and ask for confirmation; call again with confirm=true once they agree.',
            ]);
        }

        if (($refusal = $this->doubleCheck($isLive, $confirm, $confirmation_phrase, 'MODIFICAR')) !== null) {
            return $refusal;
        }

        $changes = ['payment_providers' => $newProviders];
        if ($args['enabled'] && $text !== '') {
            $changes['offline_payment_instructions'] = $newInstructions;
        }

        $this->updateSettings->handle(new PartialUpdateEventSettingsDTO(
            account_id: $this->context->accountId,
            event_id: $event->getId(),
            settings: $changes,
        ));

        $this->logWrite('offline_payment_set', ['event_id' => $event->getId(), 'enabled' => (bool)$args['enabled']]);

        return $this->toJson([
            'status' => 'applied',
            ...$payload,
            'next_steps' => $args['enabled']
                ? 'Offline payment is on. If the event is still a draft, check get_event_setup_status: it can now be published without MercadoPago.'
                : 'Offline payment is off. Make sure another payment method remains, or paid tickets cannot be sold.',
        ]);
    }
}
