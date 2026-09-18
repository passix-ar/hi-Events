<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Domain\Tools;

use HiEvents\Assistant\Domain\AssistantContext;
use HiEvents\DomainObjects\Enums\AttendeeDetailsCollectionMethod;
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
 * The checkout and email details a new organizer never thinks about until a
 * buyer writes: support email, terms / refund policy shown before paying,
 * the message after paying, reservation time, whether each attendee fills
 * their own details, marketing opt-in, and the "new order" notification.
 * Only the fields given change; the preview shows current and new.
 */
class SetCheckoutSettingsTool extends AbstractAssistantWriteTool
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
            ->as('set_checkout_settings')
            ->for('Changes the checkout and email settings of an event. Pass only what changes: support_email '
                . '(the contact buyers see), terms (text shown before paying: refund policy, rules; plain text), '
                . 'thank_you_message (shown after a completed purchase), reservation_minutes (1-120, how long a '
                . 'buyer has to pay), attendee_details "per_ticket" (each attendee gives name/email, own QR) or '
                . '"per_order" (only the buyer), marketing_opt_in checkbox, notify_new_orders (email the organizer '
                . 'on each sale). Preview without confirm; confirm=true applies; MODIFICAR on a published event.')
            ->withNumberParameter('event_id', 'The event.')
            ->withStringParameter('support_email', 'Contact email shown to buyers.', required: false)
            ->withStringParameter('terms', 'Terms / refund policy shown before paying. Plain text, line breaks allowed. Empty string clears it.', required: false)
            ->withStringParameter('thank_you_message', 'Message shown after a completed purchase. Empty string clears it.', required: false)
            ->withNumberParameter('reservation_minutes', 'Minutes a buyer has to complete payment (1-120).', required: false)
            ->withEnumParameter('attendee_details', 'Who fills attendee details at checkout.', ['per_ticket', 'per_order'], required: false)
            ->withBooleanParameter('marketing_opt_in', 'Show the marketing consent checkbox at checkout.', required: false)
            ->withBooleanParameter('notify_new_orders', 'Email the organizer on every new order.', required: false)
            ->withBooleanParameter('confirm', 'Pass true only after the organizer confirmed the preview.', required: false)
            ->withStringParameter('confirmation_phrase', 'The exact word MODIFICAR typed by the organizer, only for published events.', required: false);
    }

    public function __invoke(
        int|float      $event_id,
        ?string        $support_email = null,
        ?string        $terms = null,
        ?string        $thank_you_message = null,
        int|float|null $reservation_minutes = null,
        ?string        $attendee_details = null,
        ?bool          $marketing_opt_in = null,
        ?bool          $notify_new_orders = null,
        ?bool          $confirm = null,
        ?string        $confirmation_phrase = null,
    ): string
    {
        $args = $this->validateArguments(
            compact('event_id', 'support_email', 'terms', 'thank_you_message', 'reservation_minutes', 'attendee_details', 'marketing_opt_in', 'notify_new_orders'),
            [
                'event_id' => 'required|integer|min:1',
                'support_email' => 'nullable|email|max:255',
                'terms' => 'nullable|string|max:5000',
                'thank_you_message' => 'nullable|string|max:3000',
                'reservation_minutes' => 'nullable|integer|min:1|max:120',
                'attendee_details' => 'nullable|in:per_ticket,per_order',
                'marketing_opt_in' => 'nullable|boolean',
                'notify_new_orders' => 'nullable|boolean',
            ],
        );

        $event = $this->authorizeEvent((int)$args['event_id']);
        $isLive = $event->getStatus() === EventStatus::LIVE->name;

        /** @var EventSettingDomainObject|null $settings */
        $settings = $this->eventSettings->findFirstWhere(['event_id' => $event->getId()]);

        if ($settings === null) {
            return $this->toJson(['error' => 'tool_failed', 'details' => 'The event has no settings row.']);
        }

        $changes = [];
        $shown = [];

        if ($args['support_email'] !== null) {
            $changes['support_email'] = $args['support_email'];
            $shown['support_email'] = ['current' => $settings->getSupportEmail(), 'new' => $args['support_email']];
        }
        if ($args['terms'] !== null) {
            $changes['pre_checkout_message'] = $this->html($args['terms']);
            $shown['terms'] = ['current' => $this->text($settings->getPreCheckoutMessage()), 'new' => trim($args['terms'])];
        }
        if ($args['thank_you_message'] !== null) {
            $changes['post_checkout_message'] = $this->html($args['thank_you_message']);
            $shown['thank_you_message'] = ['current' => $this->text($settings->getPostCheckoutMessage()), 'new' => trim($args['thank_you_message'])];
        }
        if ($args['reservation_minutes'] !== null) {
            $changes['order_timeout_in_minutes'] = (int)$args['reservation_minutes'];
            $shown['reservation_minutes'] = ['current' => $settings->getOrderTimeoutInMinutes(), 'new' => (int)$args['reservation_minutes']];
        }
        if ($args['attendee_details'] !== null) {
            $method = $args['attendee_details'] === 'per_ticket'
                ? AttendeeDetailsCollectionMethod::PER_TICKET->name
                : AttendeeDetailsCollectionMethod::PER_ORDER->name;
            $changes['attendee_details_collection_method'] = $method;
            $changes['require_attendee_details'] = true;
            $shown['attendee_details'] = [
                'current' => $settings->getAttendeeDetailsCollectionMethod() === AttendeeDetailsCollectionMethod::PER_ORDER->name ? 'per_order' : 'per_ticket',
                'new' => $args['attendee_details'],
            ];
        }
        if ($args['marketing_opt_in'] !== null) {
            $changes['show_marketing_opt_in'] = (bool)$args['marketing_opt_in'];
            $shown['marketing_opt_in'] = ['current' => $settings->getShowMarketingOptIn(), 'new' => (bool)$args['marketing_opt_in']];
        }
        if ($args['notify_new_orders'] !== null) {
            $changes['notify_organizer_of_new_orders'] = (bool)$args['notify_new_orders'];
            $shown['notify_new_orders'] = ['current' => $settings->getNotifyOrganizerOfNewOrders(), 'new' => (bool)$args['notify_new_orders']];
        }

        if ($changes === []) {
            return $this->toJson([
                'status' => 'nothing_to_change',
                'current' => [
                    'support_email' => $settings->getSupportEmail(),
                    'terms' => $this->text($settings->getPreCheckoutMessage()),
                    'thank_you_message' => $this->text($settings->getPostCheckoutMessage()),
                    'reservation_minutes' => $settings->getOrderTimeoutInMinutes(),
                    'attendee_details' => $settings->getAttendeeDetailsCollectionMethod() === AttendeeDetailsCollectionMethod::PER_ORDER->name ? 'per_order' : 'per_ticket',
                    'marketing_opt_in' => $settings->getShowMarketingOptIn(),
                    'notify_new_orders' => $settings->getNotifyOrganizerOfNewOrders(),
                ],
            ]);
        }

        $payload = ['event_id' => $event->getId(), 'event_title' => $this->clip($event->getTitle()), 'event_status' => $event->getStatus(), 'changes' => $shown];

        if ($confirm !== true) {
            return $this->toJson([
                'status' => 'needs_confirmation',
                ...$payload,
                'hint' => $isLive
                    ? 'This event is published: show the changes and ask the organizer to reply with the exact word MODIFICAR before calling again with confirm=true and confirmation_phrase.'
                    : 'Show what changes and ask for confirmation; call again with confirm=true once they agree.',
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

        $this->logWrite('checkout_settings_set', ['event_id' => $event->getId(), 'fields' => array_keys($changes)]);

        return $this->toJson(['status' => 'applied', ...$payload, 'next_steps' => 'Done. Continue with the next setup step.']);
    }

    private function html(string $text): ?string
    {
        $text = trim($text);

        return $text === '' ? null : $this->purifier->purify(nl2br(e($text)));
    }

    private function text(?string $html): ?string
    {
        return $html === null ? null : trim(strip_tags(str_replace(['<br />', '<br>'], "\n", $html)));
    }
}
