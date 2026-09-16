<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Domain\Tools;

use HiEvents\Assistant\Domain\AssistantContext;
use HiEvents\Assistant\Exceptions\AssistantToolArgumentException;
use HiEvents\DomainObjects\Enums\PromoCodeDiscountTypeEnum;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\DomainObjects\PromoCodeDomainObject;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductPriceRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Repository\Interfaces\PromoCodeRepositoryInterface;
use HiEvents\Services\Application\Handlers\PromoCode\CreatePromoCodeHandler;
use HiEvents\Services\Application\Handlers\PromoCode\DTO\UpsertPromoCodeDTO;
use HiEvents\Services\Infrastructure\Authorization\IsAuthorizedService;
use Illuminate\Support\Carbon;
use Psr\Log\LoggerInterface;

/**
 * The one write tool that is allowed on a LIVE event: a promo code is how an
 * organizer reacts to slow sales, and waiting for a draft would defeat it. The
 * blast radius is bounded instead - every code has a usage cap and an expiry,
 * a percentage never passes 50% and a fixed amount never reaches the cheapest
 * paid ticket, so the worst case is a bounded discount, never a free event.
 */
class CreatePromoCodeTool extends AbstractAssistantWriteTool
{
    private const MAX_PERCENTAGE = 50;
    private const MAX_USES = 1000;
    private const MAX_DAYS_AHEAD = 180;
    private const CODE_PATTERN = '/^[A-Z0-9_-]{3,32}$/';

    public function __construct(
        AssistantContext                                 $context,
        IsAuthorizedService                              $isAuthorizedService,
        EventRepositoryInterface                         $events,
        LoggerInterface                                  $logger,
        private readonly CreatePromoCodeHandler          $createPromoCode,
        private readonly PromoCodeRepositoryInterface    $promoCodes,
        private readonly ProductRepositoryInterface      $products,
        private readonly ProductPriceRepositoryInterface $prices,
    )
    {
        parent::__construct($context, $isAuthorizedService, $events, $logger);
    }

    protected function configure(): void
    {
        $this
            ->as('create_promo_code')
            ->for('Creates a discount code for one of this organizer\'s events, valid for every ticket of the event. '
                . 'Works on published events too. Every code needs a usage cap (max_uses) and an expiry date; '
                . 'a PERCENTAGE discount cannot exceed 50 and a FIXED discount must be lower than the cheapest '
                . 'paid ticket. Call it first without confirm to get a preview, show that to the organizer, and '
                . 'only call it again with confirm=true once they agree. Use find_events for the event_id.')
            ->withNumberParameter('event_id', 'The event the code belongs to.')
            ->withStringParameter('code', 'The code buyers type at checkout: 3-32 letters, digits, "_" or "-". It is stored uppercase.')
            ->withEnumParameter('discount_type', 'PERCENTAGE takes a percent off, FIXED takes an amount in the event currency off.', ['PERCENTAGE', 'FIXED'])
            ->withNumberParameter('discount', 'Percent (1-50) or amount in the event currency, depending on discount_type.')
            ->withNumberParameter('max_uses', 'How many orders can use the code (1-1000). Required.')
            ->withStringParameter('expiry_date', 'When the code stops working, "YYYY-MM-DD" or "YYYY-MM-DD HH:MM" in the organizer timezone. Must be in the future and at most 180 days ahead. Required.')
            ->withBooleanParameter('confirm', 'Pass true only after the organizer confirmed the preview.', required: false);
    }

    public function __invoke(
        int|float  $event_id,
        string     $code,
        string     $discount_type,
        int|float  $discount,
        int|float  $max_uses,
        string     $expiry_date,
        ?bool      $confirm = null,
    ): string
    {
        $args = $this->validateArguments(
            [
                'event_id' => $event_id,
                'code' => strtoupper(trim($code)),
                'discount_type' => $discount_type,
                'discount' => $discount,
                'max_uses' => $max_uses,
                'expiry_date' => $expiry_date,
            ],
            [
                'event_id' => 'required|integer|min:1',
                'code' => ['required', 'string', 'regex:' . self::CODE_PATTERN],
                'discount_type' => 'required|in:PERCENTAGE,FIXED',
                'discount' => 'required|numeric|gt:0|max:99999999',
                'max_uses' => 'required|integer|min:1|max:' . self::MAX_USES,
                'expiry_date' => 'required|string|max:20',
            ],
        );

        $event = $this->authorizeEvent((int)$args['event_id']);

        $type = PromoCodeDiscountTypeEnum::fromName($args['discount_type']);
        $amount = $this->money($args['discount']);
        $expiry = $this->parseExpiry($args['expiry_date']);

        if ($type === PromoCodeDiscountTypeEnum::PERCENTAGE && $amount > self::MAX_PERCENTAGE) {
            throw new AssistantToolArgumentException(
                sprintf('A percentage discount cannot exceed %d%%.', self::MAX_PERCENTAGE)
            );
        }

        if ($type === PromoCodeDiscountTypeEnum::FIXED) {
            $cheapest = $this->cheapestPaidPrice($event);

            if ($cheapest === null) {
                throw new AssistantToolArgumentException(
                    'A fixed discount needs a paid ticket to apply to and the event has no paid tickets.'
                );
            }

            if ($amount >= $cheapest) {
                throw new AssistantToolArgumentException(sprintf(
                    'A fixed discount must be lower than the cheapest paid ticket (%s %s).',
                    number_format($cheapest, 2, '.', ''),
                    $event->getCurrency(),
                ));
            }
        }

        $existing = $this->findExisting($event->getId(), $args['code']);

        if ($existing !== null) {
            return $this->alreadyExists($existing);
        }

        $payload = [
            'event_id' => $event->getId(),
            'event_title' => $this->clip($event->getTitle()),
            'code' => $args['code'],
            'discount_type' => $type->name,
            'discount' => $amount,
            'currency' => $type === PromoCodeDiscountTypeEnum::FIXED ? $event->getCurrency() : null,
            'applies_to' => 'all tickets of the event',
            'max_uses' => (int)$args['max_uses'],
            'expiry' => $expiry->format('Y-m-d H:i'),
            'timezone' => $this->context->timezone,
        ];

        if ($confirm !== true) {
            return $this->preview($payload);
        }

        try {
            $promoCode = $this->createPromoCode->handle($event->getId(), new UpsertPromoCodeDTO(
                code: $args['code'],
                event_id: $event->getId(),
                applicable_product_ids: [],
                discount_type: $type,
                discount: $amount,
                expiry_date: $expiry->format('Y-m-d H:i:s'),
                max_allowed_usages: (int)$args['max_uses'],
            ));
        } catch (ResourceConflictException) {
            // Someone created the same code between our check and the write.
            $existing = $this->findExisting($event->getId(), $args['code']);

            return $existing !== null
                ? $this->alreadyExists($existing)
                : $this->toJson(['status' => 'already_exists', 'code' => $args['code']]);
        }

        $this->logWrite('promo_code_created', [
            'event_id' => $event->getId(),
            'promo_code_id' => $promoCode->getId(),
            'code' => $promoCode->getCode(),
            'discount_type' => $type->name,
            'discount' => $amount,
            'max_uses' => (int)$args['max_uses'],
            'expiry' => $payload['expiry'],
        ]);

        return $this->toJson([
            'status' => 'created',
            'promo_code' => [
                'id' => $promoCode->getId(),
                'code' => $promoCode->getCode(),
                'discount_type' => $type->name,
                'discount' => $amount,
                'currency' => $payload['currency'],
                'max_uses' => (int)$args['max_uses'],
                'expiry' => $payload['expiry'],
                'timezone' => $this->context->timezone,
                'event_title' => $payload['event_title'],
            ],
            'next_steps' => 'The code is active now. The organizer shares it with buyers, who type it in the '
                . 'promo code field at checkout to get the discount.',
        ]);
    }

    /**
     * Parsed in the organizer timezone; CreatePromoCodeService converts to UTC
     * with the event timezone itself, so the DTO gets local wall time.
     */
    private function parseExpiry(string $value): Carbon
    {
        $parsed = null;

        foreach (['Y-m-d H:i', 'Y-m-d H:i:s', 'Y-m-d'] as $format) {
            if (Carbon::canBeCreatedFromFormat($value, $format)) {
                $parsed = Carbon::createFromFormat($format, $value, $this->context->timezone);
                $parsed = $format === 'Y-m-d' ? $parsed->endOfDay() : $parsed;
                break;
            }
        }

        if ($parsed === null) {
            throw new AssistantToolArgumentException('expiry_date must be "YYYY-MM-DD HH:MM" or "YYYY-MM-DD".');
        }

        $now = Carbon::now($this->context->timezone);

        if ($parsed->lessThanOrEqualTo($now)) {
            throw new AssistantToolArgumentException('expiry_date must be in the future.');
        }

        if ($parsed->greaterThan($now->copy()->addDays(self::MAX_DAYS_AHEAD))) {
            throw new AssistantToolArgumentException(
                sprintf('expiry_date can be at most %d days ahead.', self::MAX_DAYS_AHEAD)
            );
        }

        return $parsed;
    }

    private function cheapestPaidPrice(EventDomainObject $event): ?float
    {
        /** @var ProductDomainObject[] $products */
        $products = $this->products->findWhere(['event_id' => $event->getId()])->all();

        if ($products === []) {
            return null;
        }

        $productIds = array_map(static fn(ProductDomainObject $product): int => $product->getId(), $products);
        $cheapest = null;

        /** @var ProductPriceDomainObject $price */
        foreach ($this->prices->findWhereIn('product_id', $productIds) as $price) {
            $value = (float)($price->getPrice() ?? 0);

            if ($value > 0 && ($cheapest === null || $value < $cheapest)) {
                $cheapest = $value;
            }
        }

        return $cheapest;
    }

    private function findExisting(int $eventId, string $code): ?PromoCodeDomainObject
    {
        /** @var PromoCodeDomainObject|null $promoCode */
        $promoCode = $this->promoCodes->findFirstWhere([
            'event_id' => $eventId,
            'code' => $code,
        ]);

        return $promoCode;
    }

    private function alreadyExists(PromoCodeDomainObject $existing): string
    {
        return $this->toJson([
            'status' => 'already_exists',
            'promo_code' => [
                'id' => $existing->getId(),
                'code' => $existing->getCode(),
                'discount_type' => $existing->getDiscountType(),
                'discount' => $this->money($existing->getDiscount()),
            ],
            'hint' => 'This event already has a promo code with that name; nothing was created.',
        ]);
    }
}
