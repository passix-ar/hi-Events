<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Domain\Tools;

use HiEvents\Assistant\Domain\AssistantContext;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\Exceptions\AccountNotVerifiedException;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Repository\Interfaces\AccountMercadopagoPlatformRepositoryInterface;
use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductPriceRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Application\Handlers\Event\DTO\UpdateEventStatusDTO;
use HiEvents\Services\Application\Handlers\Event\UpdateEventStatusHandler;
use HiEvents\Services\Application\Handlers\EventSettings\DTO\PartialUpdateEventSettingsDTO;
use HiEvents\Services\Application\Handlers\EventSettings\PartialUpdateEventSettingsHandler;
use HiEvents\Services\Domain\Event\EventPaymentMethodsService;
use HiEvents\Services\Infrastructure\Authorization\IsAuthorizedService;
use Psr\Log\LoggerInterface;

/**
 * The only write that makes something public. Every other write tool leaves a
 * draft behind at worst; this one puts a page on the internet and tickets on
 * sale, so it asks for more than `confirm`:
 *
 *  1. The event has to be ready (tickets, and MercadoPago unless it is free).
 *  2. The organizer has to type the exact word PUBLICAR, which the model can
 *     only relay, never invent on their behalf.
 */
class PublishEventTool extends AbstractAssistantWriteTool
{
    public const CONFIRMATION_PHRASE = 'PUBLICAR';

    public function __construct(
        AssistantContext                                       $context,
        IsAuthorizedService                                    $isAuthorizedService,
        EventRepositoryInterface                               $events,
        LoggerInterface                                        $logger,
        private readonly ProductRepositoryInterface            $products,
        private readonly ProductPriceRepositoryInterface       $prices,
        private readonly AccountMercadopagoPlatformRepositoryInterface $mercadoPago,
        private readonly UpdateEventStatusHandler              $updateEventStatus,
        private readonly EventSettingsRepositoryInterface       $eventSettings,
        private readonly EventPaymentMethodsService             $paymentMethods,
        private readonly PartialUpdateEventSettingsHandler      $updateSettings,
    )
    {
        parent::__construct($context, $isAuthorizedService, $events, $logger);
    }

    protected function configure(): void
    {
        $this
            ->as('publish_event')
            ->for('Publishes a draft event: its page becomes public at the sale URL and tickets go on sale '
                . 'immediately. This is the only tool that makes something public, so it needs an explicit '
                . 'confirmation from the organizer. Check get_event_setup_status first. Call it without confirm '
                . 'to get a preview and the readiness check; then ask the organizer to reply with the exact word '
                . 'PUBLICAR, and only then call again with confirm=true and confirmation_phrase set to what they '
                . 'wrote. Never fill in the phrase yourself.')
            ->withNumberParameter('event_id', 'The draft event to publish.')
            ->withBooleanParameter('confirm', 'Pass true only after the organizer confirmed the preview.', required: false)
            ->withStringParameter('confirmation_phrase', 'The exact word the organizer typed to confirm (must be PUBLICAR).', required: false);
    }

    public function __invoke(
        int|float $event_id,
        ?bool     $confirm = null,
        ?string   $confirmation_phrase = null,
    ): string
    {
        $args = $this->validateArguments(
            ['event_id' => $event_id, 'confirmation_phrase' => $confirmation_phrase],
            ['event_id' => 'required|integer|min:1', 'confirmation_phrase' => 'nullable|string|max:50'],
        );

        $event = $this->authorizeEvent((int)$args['event_id']);

        if ($event->getStatus() === EventStatus::LIVE->name) {
            return $this->toJson([
                'status' => 'already_live',
                'event' => $this->summary($event),
                'public_url' => $event->getEventUrl(),
                'hint' => 'This event is already published; nothing changed.',
            ]);
        }

        if ($event->getStatus() !== EventStatus::DRAFT->name) {
            return $this->toJson([
                'error' => 'event_not_draft',
                'details' => sprintf('Only drafts can be published from here; this event is %s.', $event->getStatus()),
            ]);
        }

        $missing = $this->missingBeforePublishing($event);

        if ($missing !== []) {
            return $this->toJson([
                'error' => 'not_ready',
                'missing' => $missing,
                'hint' => 'Fix what is missing first (create_ticket, or get_panel_route for "connect_mercadopago"), then try again.',
            ]);
        }

        if ($confirm !== true) {
            return $this->preview([
                'action' => 'publish',
                'event_id' => $event->getId(),
                'event_title' => $this->clip($event->getTitle()),
                'public_url' => $event->getEventUrl(),
                'what_happens' => 'The event page becomes public at that URL and its tickets go on sale immediately. '
                    . 'Explain this to the organizer in one line and ask them to reply with the exact word '
                    . self::CONFIRMATION_PHRASE . '. Then call again with confirm=true and confirmation_phrase '
                    . 'set to what they wrote.',
            ]);
        }

        if ($confirmation_phrase !== self::CONFIRMATION_PHRASE) {
            return $this->toJson([
                'error' => 'confirmation_phrase_required',
                'details' => 'Ask the organizer to reply with the exact word ' . self::CONFIRMATION_PHRASE . '.',
            ]);
        }

        try {
            if ($this->hasPaidPrice($this->products->findWhere(['event_id' => $event->getId()])->all())) {
                $this->enableMercadoPagoIfNeeded($event);
            }

            $published = $this->updateEventStatus->handle(new UpdateEventStatusDTO(
                status: EventStatus::LIVE->name,
                eventId: $event->getId(),
                accountId: $this->context->accountId,
            ));
        } catch (AccountNotVerifiedException|ResourceConflictException $e) {
            return $this->toJson([
                'error' => 'cannot_publish',
                'details' => $e->getMessage(),
            ]);
        }

        $this->logWrite('event_published', [
            'event_id' => $published->getId(),
            'title' => $published->getTitle(),
        ]);

        return $this->toJson([
            'status' => 'published',
            'event' => $this->summary($published),
            'public_url' => $published->getEventUrl(),
            'next_steps' => 'Hand the organizer the public link. Offer get_event_promo_kit to write the announcement.',
        ]);
    }

    /**
     * The readiness gate, kept local so this tool stays self-contained: at least
     * one ticket, and MercadoPago connected unless every price is 0.
     *
     * @return string[]
     */
    private function missingBeforePublishing(EventDomainObject $event): array
    {
        /** @var ProductDomainObject[] $products */
        $products = $this->products->findWhere(['event_id' => $event->getId()])->all();

        if ($products === []) {
            return ['tickets: the event has no ticket types yet'];
        }

        if (!$this->hasPaidPrice($products)) {
            return [];
        }

        // Same rule the panel enforces on publish (EventPaymentMethodsService):
        // the EVENT must list a usable provider. An event created before the
        // account connected MercadoPago has none; that case is fixed on publish.
        if ($this->usableProvidersOf($event) !== [] || $this->canEnableMercadoPago()) {
            return [];
        }

        return ['mercadopago: the event has paid tickets and no usable payment method - connect MercadoPago on the account, or enable offline payments in the event settings'];
    }

    /**
     * @return list<string>
     */
    private function usableProvidersOf(EventDomainObject $event): array
    {
        $settings = $this->settingsOf($event);

        return $this->paymentMethods->getUsableProviders(
            is_array($settings?->getPaymentProviders()) ? $settings->getPaymentProviders() : null,
            $this->context->accountId,
        );
    }

    private function canEnableMercadoPago(): bool
    {
        return $this->mercadoPago->isSetupCompleteForAccount($this->context->accountId);
    }

    private function settingsOf(EventDomainObject $event): ?EventSettingDomainObject
    {
        /** @var EventSettingDomainObject|null $settings */
        $settings = $this->eventSettings->findFirstWhere(['event_id' => $event->getId()]);

        return $settings;
    }

    /**
     * The organizer connected MercadoPago after creating this event, so the event
     * still lists no provider. Enabling it is what they would do in the panel
     * before pressing publish; it is logged as its own write.
     */
    private function enableMercadoPagoIfNeeded(EventDomainObject $event): void
    {
        if ($this->usableProvidersOf($event) !== [] || !$this->canEnableMercadoPago()) {
            return;
        }

        $settings = $this->settingsOf($event);
        $current = is_array($settings?->getPaymentProviders()) ? $settings->getPaymentProviders() : [];

        $this->updateSettings->handle(new PartialUpdateEventSettingsDTO(
            account_id: $this->context->accountId,
            event_id: $event->getId(),
            settings: ['payment_providers' => array_values(array_unique([...$current, PaymentProviders::MERCADOPAGO->value]))],
        ));

        $this->logWrite('payment_provider_enabled', ['event_id' => $event->getId(), 'provider' => PaymentProviders::MERCADOPAGO->value]);
    }

    /**
     * @param ProductDomainObject[] $products
     */
    private function hasPaidPrice(array $products): bool
    {
        $productIds = array_map(static fn(ProductDomainObject $product): int => $product->getId(), $products);

        /** @var ProductPriceDomainObject $price */
        foreach ($this->prices->findWhereIn('product_id', $productIds) as $price) {
            if ((float)($price->getPrice() ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }

    private function summary(EventDomainObject $event): array
    {
        return [
            'id' => $event->getId(),
            'title' => $this->clip($event->getTitle()),
            'status' => $event->getStatus(),
        ];
    }
}
