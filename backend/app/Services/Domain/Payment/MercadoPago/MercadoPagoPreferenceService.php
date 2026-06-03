<?php

// Added by Passix on 2026-05-25: MercadoPago Marketplace integration.
namespace HiEvents\Services\Domain\Payment\MercadoPago;

use HiEvents\DomainObjects\AccountConfigurationDomainObject;
use HiEvents\DomainObjects\AccountMercadopagoPlatformDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
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

        try {
            $client = new PreferenceClient();

            $preference = $client->create([
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
            ]);

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

    private function buildItems(OrderDomainObject $order): array
    {
        $orderItems = $order->getOrderItems();

        if (!$orderItems || $orderItems->isEmpty()) {
            return [[
                'id'          => $order->getShortId(),
                'title'       => __('Order :id', ['id' => $order->getShortId()]),
                'quantity'    => 1,
                'unit_price'  => (float) $order->getTotalGross(),
                'currency_id' => strtoupper($order->getCurrency()),
            ]];
        }

        return $orderItems->map(static fn(OrderItemDomainObject $item) => [
            'id'          => (string) $item->getId(),
            'title'       => $item->getItemName() ?? __('Ticket'),
            'quantity'    => $item->getQuantity(),
            'unit_price'  => $item->getQuantity() > 0
                ? (float) ($item->getTotalBeforeAdditions() / $item->getQuantity())
                : (float) $item->getTotalBeforeAdditions(),
            'currency_id' => strtoupper($order->getCurrency()),
        ])->values()->toArray();
    }
}
