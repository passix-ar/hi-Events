<?php

// Added by Passix: single source of truth for which payment methods an event can actually use.
namespace HiEvents\Services\Domain\Event;

use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Repository\Interfaces\AccountMercadopagoPlatformRepositoryInterface;

class EventPaymentMethodsService
{
    public function __construct(
        private readonly AccountMercadopagoPlatformRepositoryInterface $platformRepository,
    )
    {
    }

    /**
     * The providers the checkout can process right now. Offline always works;
     * MercadoPago only when the account holds a live connection. Anything else
     * (Stripe) is not supported by this fork and never reaches the checkout.
     */
    public function getUsableProviders(?array $paymentProviders, int $accountId): array
    {
        if (!is_array($paymentProviders)) {
            return [];
        }

        $isMercadoPagoConnected = null;

        $usable = array_filter($paymentProviders, function ($provider) use ($accountId, &$isMercadoPagoConnected) {
            if ($provider === PaymentProviders::OFFLINE->value) {
                return true;
            }

            if ($provider !== PaymentProviders::MERCADOPAGO->value) {
                return false;
            }

            $isMercadoPagoConnected ??= $this->platformRepository->isSetupCompleteForAccount($accountId);

            return $isMercadoPagoConnected;
        });

        return array_values($usable);
    }

    /**
     * @throws ResourceConflictException
     */
    public function assertHasUsableProvider(?array $paymentProviders, int $accountId): void
    {
        if ($this->getUsableProviders($paymentProviders, $accountId) !== []) {
            return;
        }

        $wantsMercadoPago = is_array($paymentProviders)
            && in_array(PaymentProviders::MERCADOPAGO->value, $paymentProviders, true);

        if ($wantsMercadoPago) {
            throw new ResourceConflictException(
                __('MercadoPago is not connected. Please connect MercadoPago or enable offline payments.'),
            );
        }

        throw new ResourceConflictException(
            __('Please configure at least one payment method (MercadoPago or Offline).'),
        );
    }
}
