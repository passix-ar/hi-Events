<?php

namespace Tests\Unit\Services\Application\Handlers\Order\Payment\MercadoPago;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use HiEvents\Services\Application\Handlers\Order\Payment\MercadoPago\DTO\MercadoPagoWebhookDTO;
use HiEvents\Services\Application\Handlers\Order\Payment\MercadoPago\IncomingWebhookHandler;
use HiEvents\Services\Domain\Payment\MercadoPago\EventHandlers\PaymentApprovedHandler;
use HiEvents\Services\Domain\Payment\MercadoPago\EventHandlers\PaymentRefundedHandler;
use HiEvents\Services\Domain\Payment\MercadoPago\EventHandlers\PaymentRejectedHandler;
use Illuminate\Cache\Repository as Cache;
use Illuminate\Config\Repository as Config;
use Mockery;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class IncomingWebhookHandlerTest extends TestCase
{
    private PaymentApprovedHandler|MockInterface $approvedHandler;
    private PaymentRejectedHandler|MockInterface $rejectedHandler;
    private PaymentRefundedHandler|MockInterface $refundedHandler;
    private Config|MockInterface $config;
    private Cache|MockInterface $cache;
    private Client|MockInterface $httpClient;
    private LoggerInterface|MockInterface $logger;
    private IncomingWebhookHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->approvedHandler = Mockery::mock(PaymentApprovedHandler::class);
        $this->rejectedHandler = Mockery::mock(PaymentRejectedHandler::class);
        $this->refundedHandler = Mockery::mock(PaymentRefundedHandler::class);
        $this->config = Mockery::mock(Config::class);
        $this->cache = Mockery::mock(Cache::class);
        $this->httpClient = Mockery::mock(Client::class);
        $this->logger = Mockery::mock(LoggerInterface::class);

        $this->handler = new IncomingWebhookHandler(
            $this->approvedHandler,
            $this->rejectedHandler,
            $this->refundedHandler,
            $this->config,
            $this->cache,
            $this->httpClient,
            $this->logger,
        );
    }

    public function test_approved_payment_dispatches_approved_handler(): void
    {
        $paymentId = '123456789';
        $payload = json_encode([
            'type' => 'payment',
            'data' => ['id' => $paymentId],
        ]);

        $paymentData = [
            'id'                 => $paymentId,
            'status'             => 'approved',
            'external_reference' => 'ORDER-SHORT-ID',
            'transaction_amount' => 1500.0,
            'currency_id'        => 'ARS',
        ];

        $this->logger->shouldReceive('debug')->zeroOrMoreTimes();
        $this->logger->shouldReceive('warning')->zeroOrMoreTimes();
        $this->cache->shouldReceive('has')->with('mp_webhook_' . $paymentId)->andReturn(false);
        $this->config->shouldReceive('get')->with('mercadopago.webhook_secret')->andReturn(null);
        $this->config->shouldReceive('get')->with('app.env')->andReturn('testing');
        $this->config->shouldReceive('get')->with('mercadopago.platform_access_token')->andReturn('test_token');

        $responseMock = new Response(200, [], json_encode($paymentData));
        $this->httpClient->shouldReceive('get')->once()->andReturn($responseMock);

        $this->approvedHandler->shouldReceive('handle')->once()->with($paymentData);
        $this->cache->shouldReceive('put')->once();

        $this->handler->handle(new MercadoPagoWebhookDTO(
            payload: $payload,
            xSignature: '',
            xRequestId: '',
        ));
    }

    public function test_already_processed_webhook_is_skipped(): void
    {
        $paymentId = '999';
        $payload = json_encode([
            'type' => 'payment',
            'data' => ['id' => $paymentId],
        ]);

        $this->logger->shouldReceive('debug')->zeroOrMoreTimes();
        $this->cache->shouldReceive('has')->with('mp_webhook_' . $paymentId)->andReturn(true);

        $this->approvedHandler->shouldNotReceive('handle');
        $this->rejectedHandler->shouldNotReceive('handle');
        $this->refundedHandler->shouldNotReceive('handle');

        $this->handler->handle(new MercadoPagoWebhookDTO(
            payload: $payload,
            xSignature: '',
            xRequestId: '',
        ));
    }

    public function test_missing_secret_in_production_rejects_webhook(): void
    {
        $paymentId = '777';
        $payload = json_encode([
            'type' => 'payment',
            'data' => ['id' => $paymentId],
        ]);

        $this->logger->shouldReceive('debug')->zeroOrMoreTimes();
        $this->logger->shouldReceive('error')->atLeast()->once();
        $this->cache->shouldReceive('has')->with('mp_webhook_' . $paymentId)->andReturn(false);
        $this->config->shouldReceive('get')->with('mercadopago.webhook_secret')->andReturn(null);
        $this->config->shouldReceive('get')->with('app.env')->andReturn('production');

        $this->httpClient->shouldNotReceive('get');
        $this->approvedHandler->shouldNotReceive('handle');
        $this->rejectedHandler->shouldNotReceive('handle');
        $this->refundedHandler->shouldNotReceive('handle');

        $this->handler->handle(new MercadoPagoWebhookDTO(
            payload: $payload,
            xSignature: '',
            xRequestId: '',
        ));
    }

    public function test_non_payment_topic_is_ignored(): void
    {
        $payload = json_encode([
            'type' => 'merchant_order',
            'data' => ['id' => '111'],
        ]);

        $this->logger->shouldReceive('debug')->zeroOrMoreTimes();
        $this->cache->shouldNotReceive('has');
        $this->approvedHandler->shouldNotReceive('handle');

        $this->handler->handle(new MercadoPagoWebhookDTO(
            payload: $payload,
            xSignature: '',
            xRequestId: '',
        ));
    }
}
