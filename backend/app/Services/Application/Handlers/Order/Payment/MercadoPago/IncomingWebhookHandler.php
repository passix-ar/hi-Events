<?php

// Added by Passix on 2026-05-25: MercadoPago Marketplace integration.
namespace HiEvents\Services\Application\Handlers\Order\Payment\MercadoPago;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use HiEvents\Services\Application\Handlers\Order\Payment\MercadoPago\DTO\MercadoPagoWebhookDTO;
use HiEvents\Services\Domain\Payment\MercadoPago\EventHandlers\PaymentApprovedHandler;
use HiEvents\Services\Domain\Payment\MercadoPago\EventHandlers\PaymentRefundedHandler;
use HiEvents\Services\Domain\Payment\MercadoPago\EventHandlers\PaymentRejectedHandler;
use Illuminate\Cache\Repository as Cache;
use Illuminate\Config\Repository as Config;
use Psr\Log\LoggerInterface;
use Throwable;

class IncomingWebhookHandler
{
    public function __construct(
        private readonly PaymentApprovedHandler  $approvedHandler,
        private readonly PaymentRejectedHandler  $rejectedHandler,
        private readonly PaymentRefundedHandler  $refundedHandler,
        private readonly Config                  $config,
        private readonly Cache                   $cache,
        private readonly Client                  $httpClient,
        private readonly LoggerInterface         $logger,
    ) {
    }

    /**
     * @throws Throwable
     */
    public function handle(MercadoPagoWebhookDTO $dto): void
    {
        $payload = json_decode($dto->payload, true, 512, JSON_THROW_ON_ERROR);

        $this->logger->debug('MercadoPago webhook received', ['payload' => $payload]);

        $topic = $payload['type'] ?? $payload['topic'] ?? null;
        $resourceId = $payload['data']['id'] ?? $payload['id'] ?? null;

        if (!$topic || !$resourceId) {
            $this->logger->warning('MercadoPago webhook missing topic or resource id', $payload);
            return;
        }

        if ($topic !== 'payment') {
            $this->logger->debug('MercadoPago webhook: ignoring non-payment topic', ['topic' => $topic]);
            return;
        }

        $cacheKey = 'mp_webhook_' . $resourceId;
        if ($this->cache->has($cacheKey)) {
            $this->logger->debug('MercadoPago webhook already processed', ['resource_id' => $resourceId]);
            return;
        }

        $secret = $this->config->get('mercadopago.webhook_secret');
        if ($secret && !$this->verifySignature($dto)) {
            $this->logger->warning('MercadoPago webhook signature verification failed');
            return;
        }

        $paymentData = $this->fetchPaymentData((string) $resourceId);

        if (!$paymentData) {
            $this->logger->error('Failed to fetch MercadoPago payment data', ['resource_id' => $resourceId]);
            return;
        }

        $status = $paymentData['status'] ?? null;

        match ($status) {
            'approved'               => $this->approvedHandler->handle($paymentData),
            'rejected', 'cancelled'  => $this->rejectedHandler->handle($paymentData),
            'refunded', 'charged_back' => $this->refundedHandler->handle($paymentData),
            default => $this->logger->debug('MercadoPago webhook: no handler for status', ['status' => $status]),
        };

        $this->cache->put($cacheKey, true, now()->addMinutes(60));
    }

    private function verifySignature(MercadoPagoWebhookDTO $dto): bool
    {
        $secret = $this->config->get('mercadopago.webhook_secret');

        if (!$secret || !$dto->xSignature || !$dto->xRequestId) {
            return false;
        }

        $payload = json_decode($dto->payload, true);
        $dataId  = $payload['data']['id'] ?? '';

        $manifest = "id:{$dataId};request-id:{$dto->xRequestId};ts:";

        preg_match('/ts=(\d+)/', $dto->xSignature, $tsMatch);
        $ts = $tsMatch[1] ?? '';
        $manifest .= $ts;

        preg_match('/v1=([^,]+)/', $dto->xSignature, $hashMatch);
        $receivedHash = $hashMatch[1] ?? '';

        $expectedHash = hash_hmac('sha256', $manifest, $secret);

        return hash_equals($expectedHash, $receivedHash);
    }

    private function fetchPaymentData(string $paymentId): ?array
    {
        try {
            $platformToken = $this->config->get('mercadopago.platform_access_token');

            $response = $this->httpClient->get("https://api.mercadopago.com/v1/payments/{$paymentId}", [
                'headers' => [
                    'Authorization' => 'Bearer ' . $platformToken,
                ],
            ]);

            return json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
        } catch (GuzzleException $e) {
            $this->logger->error('Failed to fetch MercadoPago payment data', [
                'payment_id' => $paymentId,
                'error'      => $e->getMessage(),
            ]);
            return null;
        }
    }
}
