<?php

// Added by Passix on 2026-05-25: MercadoPago Marketplace integration.
namespace HiEvents\Services\Domain\Payment\MercadoPago;

use HiEvents\DomainObjects\AccountConfigurationDomainObject;
use HiEvents\DomainObjects\AccountMercadopagoPlatformDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\Exceptions\MercadoPago\CreateMercadoPagoPreferenceFailedException;
use HiEvents\Exceptions\MercadoPago\MercadoPagoClientConfigurationException;
use HiEvents\Services\Domain\Order\OrderApplicationFeeCalculationService;
use Illuminate\Config\Repository as Config;
use MercadoPago\Client\Preference\PreferenceClient;
use MercadoPago\MercadoPagoConfig;
use Psr\Log\LoggerInterface;
use Throwable;

class MercadoPagoPreferenceService
{
    public function __construct(
        private readonly Config                             $config,
        private readonly LoggerInterface                   $logger,
        private readonly OrderApplicationFeeCalculationService $feeCalculationService,
    ) {
    }

    /**
     * Create a Checkout Pro preference using the organizer's OAuth access token.
     * The marketplace_fee is routed to the platform account using the same fee
     * calculation as Stripe (from account_configurations, not env vars).
     *
     * @throws MercadoPagoClientConfigurationException
     * @throws CreateMercadoPagoPreferenceFailedException
     */
    public function createPreference(
        OrderDomainObject                      $order,
        AccountMercadopagoPlatformDomainObject $platform,
        ?AccountConfigurationDomainObject      $accountConfiguration,
        string                                 $successUrl,
        string                                 $failureUrl,
        string                                 $pendingUrl,
        string                                 $webhookUrl,
    ): object {
        if (!$platform->getAccessToken()) {
            throw new MercadoPagoClientConfigurationException(
                __('MercadoPago is not connected for this organizer.')
            );
        }

        MercadoPagoConfig::setAccessToken($platform->getAccessToken());
        MercadoPagoConfig::setRuntimeEnviroment(MercadoPagoConfig::LOCAL);

        $marketplaceFee = $this->calculateMarketplaceFee($accountConfiguration, $order);
        $items = $this->buildItems($order);
        $payer = $this->buildPayer($order);

        try {
            $client = new PreferenceClient();

            $preferenceData = [
                'items'              => $items,
                'marketplace_fee'    => $marketplaceFee,
                'external_reference' => $order->getShortId(),
                'back_urls'          => [
                    'success' => $successUrl,
                    'failure' => $failureUrl,
                    'pending' => $pendingUrl,
                ],
                'auto_return'        => 'approved',
                'notification_url'   => $webhookUrl,
                'statement_descriptor' => substr(config('app.name', 'Passix'), 0, 22),
            ];

            if ($payer) {
                $preferenceData['payer'] = $payer;
            }

            $preference = $client->create($preferenceData);

            $this->logger->info('MercadoPago preference created', [
                'preference_id'   => $preference->id,
                'order_id'        => $order->getId(),
                'marketplace_fee' => $marketplaceFee,
            ]);

            return $preference;
        } catch (Throwable $e) {
            $this->logger->error('Failed to create MercadoPago preference', [
                'order_id' => $order->getId(),
                'error'    => $e->getMessage(),
            ]);

            throw new CreateMercadoPagoPreferenceFailedException(
                __('Failed to create MercadoPago payment preference. Please try again.'),
                previous: $e,
            );
        }
    }

    private function calculateMarketplaceFee(
        ?AccountConfigurationDomainObject $accountConfiguration,
        OrderDomainObject                 $order,
    ): float {
        if (!$accountConfiguration || !$this->config->get('app.saas_mode_enabled')) {
            return 0.0;
        }

        if ($accountConfiguration->getBypassApplicationFees()) {
            return 0.0;
        }

        $fee = $this->feeCalculationService->calculateApplicationFee($accountConfiguration, $order);

        return $fee ? round($fee->grossApplicationFee->toFloat(), 2) : 0.0;
    }

    /**
     * Build the Checkout Pro line items.
     *
     * We charge a single line equal to the order's total_gross. This is the amount
     * the buyer actually owes — it already includes taxes, service fees and (when
     * enabled) the platform fee passed through to the buyer. Itemising per ticket
     * with the pre-additions price would undercharge whenever any of those exist,
     * so a single authoritative total avoids any mismatch with the order.
     */
    private function buildItems(OrderDomainObject $order): array
    {
        $event = $order->getEvent();
        $title = $event?->getTitle()
            ? __('Tickets · :event', ['event' => $event->getTitle()])
            : __('Order :id', ['id' => $order->getShortId()]);

        return [[
            'id'          => $order->getShortId(),
            'title'       => $title,
            'description' => $title,
            'category_id' => 'tickets',
            'quantity'    => 1,
            'unit_price'  => round((float) $order->getTotalGross(), 2),
            'currency_id' => strtoupper($order->getCurrency()),
        ]];
    }

    /**
     * Build the Checkout Pro payer block from the order's buyer details.
     *
     * MercadoPago uses this to improve approval rates and integration quality
     * scoring. Only the fields we actually have are included.
     */
    private function buildPayer(OrderDomainObject $order): ?array
    {
        $payer = array_filter([
            'name'    => $order->getFirstName(),
            'surname' => $order->getLastName(),
            'email'   => $order->getEmail(),
        ], static fn ($value) => $value !== null && $value !== '');

        return $payer === [] ? null : $payer;
    }
}
