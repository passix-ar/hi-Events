<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Domain\Tools;

use HiEvents\Assistant\Domain\AssistantContext;
use HiEvents\DomainObjects\Enums\MessageTypeEnum;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\MessageStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Exceptions\AccountNotVerifiedException;
use HiEvents\Exceptions\MessagingTierLimitExceededException;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Application\Handlers\Message\DTO\SendMessageDTO;
use HiEvents\Services\Application\Handlers\Message\SendMessageHandler;
use HiEvents\Services\Infrastructure\Authorization\IsAuthorizedService;
use Illuminate\Validation\ValidationException;
use Psr\Log\LoggerInterface;

/**
 * The first tool whose effect leaves the platform: it emails real buyers and
 * attendees of one event. Nothing about it is a draft, so it carries the same
 * bar as publish_event and a bit more:
 *
 *  1. It counts recipients before doing anything and refuses to send to nobody.
 *  2. Without `confirm` it only previews the subject, body, audience and count.
 *  3. Sending needs the exact word ENVIAR typed by the organizer, which the
 *     model can only relay, never invent.
 *  4. The organizer always gets a copy, so a wrong send is at least visible.
 *
 * Everything else (account verified, per-tier limits, pending review for
 * untrusted accounts, HTML purification) is the same SendMessageHandler the
 * panel uses, so the chat cannot do anything the "Messages" page cannot.
 */
class MessageBuyersTool extends AbstractAssistantWriteTool
{
    public const CONFIRMATION_PHRASE = 'ENVIAR';

    public const AUDIENCE_ALL_ATTENDEES = 'all_attendees';
    public const AUDIENCE_ORDER_OWNERS = 'order_owners';
    public const AUDIENCE_TICKET_TYPE = 'ticket_type';

    public function __construct(
        AssistantContext                            $context,
        IsAuthorizedService                         $isAuthorizedService,
        EventRepositoryInterface                    $events,
        LoggerInterface                             $logger,
        private readonly SendMessageHandler         $sendMessage,
        private readonly ProductRepositoryInterface $products,
        private readonly AttendeeRepositoryInterface $attendees,
        private readonly OrderRepositoryInterface   $orders,
    )
    {
        parent::__construct($context, $isAuthorizedService, $events, $logger);
    }

    protected function configure(): void
    {
        $this
            ->as('message_buyers')
            ->for('Emails the buyers or attendees of one of this organizer\'s events (a real email to real '
                . 'people, sent from the platform). Use it only when the organizer explicitly asks to notify, '
                . 'write to or email their buyers/attendees. Draft the subject and message in the organizer\'s '
                . 'voice first. Call it without confirm to get a preview with the recipient count, show that to '
                . 'the organizer, ask them to reply with the exact word ENVIAR, and only then call again with '
                . 'confirm=true and confirmation_phrase set to what they wrote. Never fill in the phrase yourself. '
                . 'Audiences: all_attendees (every active attendee of the event), order_owners (the person who '
                . 'paid each completed order), ticket_type (active attendees holding one ticket type; needs '
                . 'product_id from get_event_setup_status or get_ticket_ranking). Use find_events for the event_id.')
            ->withNumberParameter('event_id', 'The event whose buyers/attendees receive the email.')
            ->withEnumParameter(
                'audience',
                'Who receives it: all_attendees, order_owners or ticket_type.',
                [self::AUDIENCE_ALL_ATTENDEES, self::AUDIENCE_ORDER_OWNERS, self::AUDIENCE_TICKET_TYPE],
            )
            ->withStringParameter('subject', 'Email subject, plain text, 3 to 120 characters.')
            ->withStringParameter('message', 'Email body, 20 to 3000 characters. Plain text; blank lines separate paragraphs and **bold** is allowed. No HTML.')
            ->withNumberParameter('product_id', 'Required when audience is ticket_type: the ticket type whose holders receive the email.', required: false)
            ->withBooleanParameter('confirm', 'Pass true only after the organizer confirmed the preview.', required: false)
            ->withStringParameter('confirmation_phrase', 'The exact word the organizer typed to confirm (must be ENVIAR).', required: false);
    }

    public function __invoke(
        int|float      $event_id,
        string         $audience,
        string         $subject,
        string         $message,
        int|float|null $product_id = null,
        ?bool          $confirm = null,
        ?string        $confirmation_phrase = null,
    ): string
    {
        $args = $this->validateArguments(
            [
                'event_id' => $event_id,
                'audience' => $audience,
                'subject' => $subject,
                'message' => $message,
                'product_id' => $product_id,
                'confirmation_phrase' => $confirmation_phrase,
            ],
            [
                'event_id' => 'required|integer|min:1',
                'audience' => 'required|string|in:' . implode(',', [
                        self::AUDIENCE_ALL_ATTENDEES,
                        self::AUDIENCE_ORDER_OWNERS,
                        self::AUDIENCE_TICKET_TYPE,
                    ]),
                'subject' => 'required|string|min:3|max:120',
                'message' => 'required|string|min:20|max:3000',
                'product_id' => 'nullable|integer|min:1|required_if:audience,' . self::AUDIENCE_TICKET_TYPE,
                'confirmation_phrase' => 'nullable|string|max:50',
            ],
        );

        $event = $this->authorizeEvent((int)$args['event_id']);

        $subjectText = trim(preg_replace('/\s+/u', ' ', strip_tags($args['subject'])) ?? '');
        $messageText = trim(str_replace("\r\n", "\n", $args['message']));

        $product = null;
        if ($args['audience'] === self::AUDIENCE_TICKET_TYPE) {
            $product = $this->findProduct($event, (int)$args['product_id']);

            if ($product === null) {
                return $this->toJson([
                    'error' => 'invalid_arguments',
                    'details' => sprintf('product_id %d is not a ticket type of event %d.', (int)$args['product_id'], $event->getId()),
                ]);
            }
        }

        $recipientCount = $this->countRecipients($event, $args['audience'], $product);

        if ($recipientCount === 0) {
            return $this->toJson([
                'error' => 'no_recipients',
                'details' => 'Nobody matches that audience on this event yet, so there is nothing to send.',
            ]);
        }

        $audienceLabel = $this->describeAudience($args['audience'], $product);

        if ($confirm !== true) {
            return $this->preview([
                'action' => 'send_email',
                'event_id' => $event->getId(),
                'event_title' => $this->clip($event->getTitle()),
                'audience' => $args['audience'],
                'audience_description' => $audienceLabel,
                'recipient_count' => $recipientCount,
                'subject' => $subjectText,
                'message' => $messageText,
                'what_happens' => sprintf(
                    'A real email with this subject and text goes to %d %s, and the organizer receives a copy. '
                    . 'Show the organizer the subject, the full text and the recipient count, and ask them to '
                    . 'reply with the exact word %s. Then call again with confirm=true and confirmation_phrase '
                    . 'set to what they wrote.',
                    $recipientCount,
                    $audienceLabel,
                    self::CONFIRMATION_PHRASE,
                ),
            ]);
        }

        if ($confirmation_phrase !== self::CONFIRMATION_PHRASE) {
            return $this->toJson([
                'error' => 'confirmation_phrase_required',
                'details' => 'Ask the organizer to reply with the exact word ' . self::CONFIRMATION_PHRASE . '.',
            ]);
        }

        try {
            // SendMessageHandler purifies the HTML before storing and sending it,
            // exactly as the panel's SendMessageAction path does.
            $sent = $this->sendMessage->handle(SendMessageDTO::fromArray([
                'account_id' => $this->context->accountId,
                'event_id' => $event->getId(),
                'subject' => $subjectText,
                'message' => $this->renderHtml($messageText),
                'type' => $this->messageType($args['audience']),
                'is_test' => false,
                'send_copy_to_current_user' => true,
                'sent_by_user_id' => $this->context->user->getId(),
                'order_statuses' => [OrderStatus::COMPLETED->name],
                'attendee_ids' => [],
                'product_ids' => $this->productIds($event, $args['audience'], $product),
            ]));
        } catch (AccountNotVerifiedException|MessagingTierLimitExceededException|ValidationException $e) {
            return $this->toJson([
                'error' => 'cannot_send',
                'details' => $e->getMessage(),
            ]);
        }

        $pendingReview = $sent->getStatus() === MessageStatus::PENDING_REVIEW->name;

        $this->logWrite('message_sent', [
            'event_id' => $event->getId(),
            'message_id' => $sent->getId(),
            'audience' => $args['audience'],
            'product_id' => $product?->getId(),
            'recipient_count' => $recipientCount,
            'subject' => $subjectText,
            'status' => $sent->getStatus(),
        ]);

        return $this->toJson([
            'status' => $pendingReview ? 'pending_review' : 'sent',
            'message_id' => $sent->getId(),
            'event_id' => $event->getId(),
            'audience' => $args['audience'],
            'recipient_count' => $recipientCount,
            'subject' => $subjectText,
            'next_steps' => $pendingReview
                ? 'The message was queued but is held for a review by the platform before it goes out (new '
                . 'accounts get this on their first sends). Tell the organizer it will be delivered once approved '
                . 'and that they will receive a copy when it is.'
                : sprintf(
                    'The email is being delivered to %d %s. Tell the organizer they will receive a copy in their own inbox.',
                    $recipientCount,
                    $audienceLabel,
                ),
        ]);
    }

    private function findProduct(EventDomainObject $event, int $productId): ?ProductDomainObject
    {
        /** @var ProductDomainObject|null $product */
        $product = $this->products->findFirstWhere([
            'id' => $productId,
            'event_id' => $event->getId(),
        ]);

        return $product;
    }

    /**
     * Counts what SendEventEmailMessagesService would actually email: active
     * attendees for the attendee audiences, completed orders for order owners.
     * Duplicated emails are collapsed at send time, so this is an upper bound.
     */
    private function countRecipients(EventDomainObject $event, string $audience, ?ProductDomainObject $product): int
    {
        return match ($audience) {
            self::AUDIENCE_ALL_ATTENDEES => $this->attendees->countWhere([
                'event_id' => $event->getId(),
                'status' => AttendeeStatus::ACTIVE->name,
            ]),
            self::AUDIENCE_TICKET_TYPE => $this->attendees->countWhere([
                'event_id' => $event->getId(),
                'product_id' => $product?->getId(),
                'status' => AttendeeStatus::ACTIVE->name,
            ]),
            self::AUDIENCE_ORDER_OWNERS => $this->countOrderOwners($event),
        };
    }

    private function countOrderOwners(EventDomainObject $event): int
    {
        $productIds = $this->allProductIds($event);

        if ($productIds === []) {
            return 0;
        }

        return $this->orders->countOrdersAssociatedWithProducts(
            eventId: $event->getId(),
            productIds: $productIds,
            orderStatuses: [OrderStatus::COMPLETED->name],
        );
    }

    /**
     * There is no "every order owner" message type; ORDER_OWNERS_WITH_PRODUCT
     * over every product of the event is how the panel reaches all of them.
     */
    private function messageType(string $audience): MessageTypeEnum
    {
        return match ($audience) {
            self::AUDIENCE_ALL_ATTENDEES => MessageTypeEnum::ALL_ATTENDEES,
            self::AUDIENCE_TICKET_TYPE => MessageTypeEnum::TICKET_HOLDERS,
            self::AUDIENCE_ORDER_OWNERS => MessageTypeEnum::ORDER_OWNERS_WITH_PRODUCT,
        };
    }

    /** @return int[] */
    private function productIds(EventDomainObject $event, string $audience, ?ProductDomainObject $product): array
    {
        return match ($audience) {
            self::AUDIENCE_ALL_ATTENDEES => [],
            self::AUDIENCE_TICKET_TYPE => [$product->getId()],
            self::AUDIENCE_ORDER_OWNERS => $this->allProductIds($event),
        };
    }

    /** @return int[] */
    private function allProductIds(EventDomainObject $event): array
    {
        return $this->products
            ->findWhere(['event_id' => $event->getId()], columns: ['id'])
            ->map(static fn(ProductDomainObject $p): int => $p->getId())
            ->values()
            ->all();
    }

    private function describeAudience(string $audience, ?ProductDomainObject $product): string
    {
        return match ($audience) {
            self::AUDIENCE_ALL_ATTENDEES => 'attendees of the event',
            self::AUDIENCE_ORDER_OWNERS => 'buyers (one per completed order)',
            self::AUDIENCE_TICKET_TYPE => sprintf('holders of "%s"', $this->clip($product?->getTitle(), 60)),
        };
    }

    /**
     * The organizer writes plain text in a chat; the email template expects HTML.
     * Everything is escaped first (the model must not be able to inject markup),
     * then blank lines become paragraphs, single newlines become <br> and
     * **bold** becomes <strong>. SendMessageHandler purifies the result anyway.
     */
    private function renderHtml(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $escaped = preg_replace('/\*\*(.+?)\*\*/su', '<strong>$1</strong>', $escaped) ?? $escaped;

        $paragraphs = preg_split('/\n{2,}/u', $escaped) ?: [$escaped];

        return implode('', array_map(
            static fn(string $p): string => '<p>' . nl2br(trim($p), false) . '</p>',
            array_filter($paragraphs, static fn(string $p): bool => trim($p) !== ''),
        ));
    }
}
