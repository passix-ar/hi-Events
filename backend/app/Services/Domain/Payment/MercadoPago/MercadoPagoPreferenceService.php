<?php

// Added by Passix on 2026-05-25: MercadoPago Marketplace integration.
namespace HiEvents\Services\Domain\Payment\MercadoPago;

use HiEvents\DomainObjects\AccountMercadopagoPlatformDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\Exceptions\MercadoPago\CreateMercadoPagoPreferenceFailedException;
use HiEvents\Exceptions\MercadoPago\MercadoPagoClientConfigurationException;
use Illuminate\Config\Repository as Config;
use MercadoPago\Client\Preference\PreferenceClient;
use MercadoPago\MercadoPagoConfig;
use Psr\Log\LoggerInterface;
use Throwable;

class MercadoPagoPreferenceService
{
    public function __construct(
        private readonly Config          $config,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Create a Checkout Pro preference using the organizer's OAuth access token.
     * The marketplace_fee is automatically routed to the platform account.
     *
     * @throws MercadoPagoClientConfigurationException
     * @throws CreateMercadoPagoPreferenceFailedException
     */
    public function createPreference(
        OrderDomainObject                    $order,
        AccountMercadopagoPlatformDomainObject $platform,
        string                               $successUrl,
        string                               $failureUrl,
        string                               $pendingUrl,
        string                               $webhookUrl,
    ): object {
        if (!$platform->getAccessToken()) {
            throw new MercadoPagoClientConfigurationException(
                __('MercadoPago is not connected for this organizer.')
            );
        }

        MercadoPagoConfig::setAccessToken($platform->getAccessToken());
        MercadoPagoConfig::setRuntimeEnviroment(MercadoPagoConfig::LOCAL);

        $marketplaceFee = $this->calculateMarketplaceFee($order->getTotalGross());
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

    private function calculateMarketplaceFee(float $orderTotal): float
    {
        $feePercent = (float) $this->config->get('mercadopago.marketplace_fee_percent', 0);

        if ($feePercent <= 0) {
            return 0.0;
        }

        return round($orderTotal * ($feePercent / 100), 2);
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
