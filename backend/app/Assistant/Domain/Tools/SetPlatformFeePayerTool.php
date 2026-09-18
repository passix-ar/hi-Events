<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Domain\Tools;

use HiEvents\Assistant\Domain\AssistantContext;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\DomainObjects\Status\EventStatus;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Services\Application\Handlers\EventSettings\DTO\GetPlatformFeePreviewDTO;
use HiEvents\Services\Application\Handlers\EventSettings\DTO\PartialUpdateEventSettingsDTO;
use HiEvents\Services\Application\Handlers\EventSettings\GetPlatformFeePreviewHandler;
use HiEvents\Services\Application\Handlers\EventSettings\PartialUpdateEventSettingsHandler;
use HiEvents\Services\Infrastructure\Authorization\IsAuthorizedService;
use Psr\Log\LoggerInterface;

/**
 * Who pays Passix's commission on this event: the buyer (added on top of the
 * price at checkout, the organizer receives the full price) or the organizer
 * (price stays clean, the fee comes out of their payout). The preview uses the
 * same calculation the panel shows, on the event's cheapest paid ticket.
 */
class SetPlatformFeePayerTool extends AbstractAssistantWriteTool
{
    public function __construct(
        AssistantContext                                   $context,
        IsAuthorizedService                                $isAuthorizedService,
        EventRepositoryInterface                           $events,
        LoggerInterface                                    $logger,
        private readonly EventSettingsRepositoryInterface  $eventSettings,
        private readonly PartialUpdateEventSettingsHandler $updateSettings,
        private readonly GetPlatformFeePreviewHandler      $feePreview,
        private readonly ProductRepositoryInterface        $products,
    )
    {
        parent::__construct($context, $isAuthorizedService, $events, $logger);
    }

    protected function configure(): void
    {
        $this
            ->as('set_platform_fee_payer')
            ->for('Sets who pays the Passix platform commission on this event: "buyer" adds it on top of the '
                . 'ticket price at checkout (the organizer receives the full price), "organizer" keeps the '
                . 'price as shown and the commission comes out of the organizer\'s payout. Call without '
                . 'confirm for a preview with real amounts on the cheapest paid ticket; confirm=true '
                . 'applies it. MODIFICAR on a published event (prices shown to buyers change).')
            ->withNumberParameter('event_id', 'The event.')
            ->withEnumParameter('payer', 'Who pays the commission.', ['buyer', 'organizer'])
            ->withBooleanParameter('confirm', 'Pass true only after the organizer confirmed the preview.', required: false)
            ->withStringParameter('confirmation_phrase', 'The exact word MODIFICAR typed by the organizer, only for published events.', required: false);
    }

    public function __invoke(int|float $event_id, string $payer, ?bool $confirm = null, ?string $confirmation_phrase = null): string
    {
        $args = $this->validateArguments(
            compact('event_id', 'payer'),
            ['event_id' => 'required|integer|min:1', 'payer' => 'required|in:buyer,organizer'],
        );

        $event = $this->authorizeEvent((int)$args['event_id']);
        $isLive = $event->getStatus() === EventStatus::LIVE->name;
        $passToBuyer = $args['payer'] === 'buyer';

        /** @var EventSettingDomainObject|null $settings */
        $settings = $this->eventSettings->findFirstWhere(['event_id' => $event->getId()]);
        $current = $settings?->getPassPlatformFeeToBuyer() ?? false;

        $samplePrice = $this->cheapestPaidPrice($event->getId());
        $preview = $this->feePreview->handle(new GetPlatformFeePreviewDTO(eventId: $event->getId(), price: $samplePrice));

        $example = [
            'sample_ticket_price' => $this->money($preview->samplePrice),
            'commission_percent' => $preview->percentageFee,
            'commission_on_sample' => $this->money($preview->platformFee),
            'if_buyer_pays' => ['buyer_pays' => $this->money($preview->total), 'organizer_receives' => $this->money($preview->samplePrice)],
            'if_organizer_pays' => ['buyer_pays' => $this->money($preview->samplePrice), 'organizer_receives' => $this->money($preview->samplePrice - $preview->platformFee)],
            'currency' => $preview->eventCurrency,
            'note' => 'MercadoPago charges its own processing fee on top, always borne by the organizer.',
        ];

        $payload = [
            'event_id' => $event->getId(),
            'event_title' => $this->clip($event->getTitle()),
            'event_status' => $event->getStatus(),
            'currently' => $current ? 'buyer' : 'organizer',
            'would_be' => $args['payer'],
            'example' => $example,
        ];

        if ($confirm !== true) {
            return $this->toJson([
                'status' => 'needs_confirmation',
                ...$payload,
                'hint' => $isLive
                    ? 'This event is published: show both amounts and ask the organizer to reply with the exact word MODIFICAR before calling again with confirm=true and confirmation_phrase.'
                    : 'Explain the two options with the example amounts and ask for confirmation; call again with confirm=true once they agree.',
            ]);
        }

        if (($refusal = $this->doubleCheck($isLive, $confirm, $confirmation_phrase, 'MODIFICAR')) !== null) {
            return $refusal;
        }

        $this->updateSettings->handle(new PartialUpdateEventSettingsDTO(
            account_id: $this->context->accountId,
            event_id: $event->getId(),
            settings: ['pass_platform_fee_to_buyer' => $passToBuyer],
        ));

        $this->logWrite('platform_fee_payer_set', ['event_id' => $event->getId(), 'payer' => $args['payer']]);

        return $this->toJson([
            'status' => 'applied',
            ...$payload,
            'next_steps' => 'Done. Continue with the next setup step.',
        ]);
    }

    private function cheapestPaidPrice(int $eventId): float
    {
        $products = $this->products->loadRelation(ProductPriceDomainObject::class)->findWhere(['event_id' => $eventId]);
        $prices = [];
        foreach ($products as $product) {
            /** @var ProductDomainObject $product */
            foreach ($product->getProductPrices() ?? [] as $price) {
                if ((float)$price->getPrice() > 0) {
                    $prices[] = (float)$price->getPrice();
                }
            }
        }

        return $prices === [] ? 10000.0 : min($prices);
    }
}
