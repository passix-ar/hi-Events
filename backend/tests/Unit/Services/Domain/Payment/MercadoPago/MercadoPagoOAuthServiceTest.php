<?php

namespace Tests\Unit\Services\Domain\Payment\MercadoPago;

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;
use HiEvents\Exceptions\MercadoPago\MercadoPagoOAuthException;
use HiEvents\Services\Domain\Payment\MercadoPago\MercadoPagoOAuthService;
use Illuminate\Config\Repository as Config;
use Mockery;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class MercadoPagoOAuthServiceTest extends TestCase
{
    private Config|MockInterface $config;
    private Client|MockInterface $httpClient;
    private LoggerInterface|MockInterface $logger;
    private MercadoPagoOAuthService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = Mockery::mock(Config::class);
        $this->httpClient = Mockery::mock(Client::class);
        $this->logger = Mockery::mock(LoggerInterface::class);

        $this->service = new MercadoPagoOAuthService(
            $this->config,
            $this->httpClient,
            $this->logger,
        );
    }

    public function test_builds_authorization_url_with_correct_params(): void
    {
        $this->config->shouldReceive('get')
            ->with('mercadopago.client_id')
            ->andReturn('test_client_id');
        $this->config->shouldReceive('get')
            ->with('mercadopago.redirect_uri')
            ->andReturn('https://example.com/callback');
        $this->config->shouldReceive('get')
            ->with('mercadopago.auth_url')
            ->andReturn('https://auth.mercadopago.com.ar/authorization');

        $url = $this->service->buildAuthorizationUrl(42);

        $this->assertStringContainsString('client_id=test_client_id', $url);
        $this->assertStringContainsString('response_type=code', $url);
        $this->assertStringContainsString('platform_id=mp', $url);
        $this->assertStringContainsString('redirect_uri=', $url);
    }

    public function test_decode_state_returns_account_id(): void
    {
        $accountId = 99;
        $encoded = base64_encode((string) $accountId);

        $result = $this->service->decodeState($encoded);

        $this->assertSame($accountId, $result);
    }

    public function test_decode_state_throws_for_invalid_input(): void
    {
        $this->expectException(MercadoPagoOAuthException::class);

        $this->service->decodeState('!!!invalid!!!');
    }

    public function test_exchange_code_for_token_returns_token_data(): void
    {
        $tokenData = [
            'access_token'  => 'APP_USR-xxx',
            'refresh_token' => 'TG-xxx',
            'user_id'       => 123456,
            'expires_in'    => 15552000,
        ];

        $this->config->shouldReceive('get')->with('mercadopago.client_id')->andReturn('id');
        $this->config->shouldReceive('get')->with('mercadopago.client_secret')->andReturn('secret');
        $this->config->shouldReceive('get')->with('mercadopago.redirect_uri')->andReturn('https://example.com/callback');
        $this->config->shouldReceive('get')->with('mercadopago.token_url')->andReturn('https://api.mercadopago.com/oauth/token');

        $responseMock = new Response(200, [], json_encode($tokenData));
        $this->httpClient->shouldReceive('post')->once()->andReturn($responseMock);

        $result = $this->service->exchangeCodeForToken('test_code');

        $this->assertSame('APP_USR-xxx', $result['access_token']);
        $this->assertSame(123456, $result['user_id']);
    }
}
