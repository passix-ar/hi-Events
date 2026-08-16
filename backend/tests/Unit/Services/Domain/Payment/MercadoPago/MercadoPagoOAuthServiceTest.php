<?php

namespace Tests\Unit\Services\Domain\Payment\MercadoPago;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use HiEvents\Exceptions\MercadoPago\MercadoPagoOAuthException;
use HiEvents\Services\Domain\Payment\MercadoPago\MercadoPagoOAuthService;
use Illuminate\Config\Repository as Config;
use Illuminate\Contracts\Encryption\Encrypter as EncrypterContract;
use Illuminate\Encryption\Encrypter;
use Mockery;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class MercadoPagoOAuthServiceTest extends TestCase
{
    private Config|MockInterface $config;
    private Client|MockInterface $httpClient;
    private LoggerInterface|MockInterface $logger;
    private EncrypterContract $encrypter;
    private MercadoPagoOAuthService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = Mockery::mock(Config::class);
        $this->httpClient = Mockery::mock(Client::class);
        $this->logger = Mockery::mock(LoggerInterface::class);
        $this->encrypter = new Encrypter(str_repeat('a', 32), 'AES-256-CBC');

        $this->service = new MercadoPagoOAuthService(
            $this->config,
            $this->httpClient,
            $this->logger,
            $this->encrypter,
        );
    }

    private function makeState(int $accountId, int $ts): string
    {
        return $this->encrypter->encrypt(['account_id' => $accountId, 'ts' => $ts]);
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

    public function test_decode_state_returns_account_id_for_valid_state(): void
    {
        $state = $this->makeState(99, time());

        $this->assertSame(99, $this->service->decodeState($state));
    }

    /**
     * Full round-trip through the public API: the state produced for the authorization
     * URL must be URL-safe and decode back to the same account id after surviving the
     * (url-encoded) redirect. This guards against breaking the working OAuth connect.
     */
    public function test_state_from_authorization_url_round_trips(): void
    {
        $this->config->shouldReceive('get')->with('mercadopago.client_id')->andReturn('id');
        $this->config->shouldReceive('get')->with('mercadopago.redirect_uri')->andReturn('https://example.com/callback');
        $this->config->shouldReceive('get')->with('mercadopago.auth_url')->andReturn('https://auth.mercadopago.com.ar/authorization');

        $url = $this->service->buildAuthorizationUrl(7);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        $state = $query['state'];
        $this->assertDoesNotMatchRegularExpression('/[+\/=]/', $state, 'State must be URL-safe (no + / =)');
        $this->assertSame(7, $this->service->decodeState($state));
    }

    /**
     * Security regression: the old forgeable format (base64 of the raw account id)
     * must be rejected. Otherwise an attacker could craft a state pointing at any
     * organizer's account and hijack their MercadoPago connection.
     */
    public function test_decode_state_rejects_forged_legacy_state(): void
    {
        $this->expectException(MercadoPagoOAuthException::class);

        $this->service->decodeState(base64_encode('99'));
    }

    public function test_decode_state_rejects_expired_state(): void
    {
        $this->expectException(MercadoPagoOAuthException::class);

        $this->service->decodeState($this->makeState(99, time() - 1000));
    }

    public function test_decode_state_rejects_state_encrypted_with_a_different_key(): void
    {
        $foreignEncrypter = new Encrypter(str_repeat('b', 32), 'AES-256-CBC');
        $foreignState = $foreignEncrypter->encrypt(['account_id' => 99, 'ts' => time()]);

        $this->expectException(MercadoPagoOAuthException::class);

        $this->service->decodeState($foreignState);
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

    public function test_refresh_access_token_sends_refresh_grant_and_returns_token_data(): void
    {
        $tokenData = [
            'access_token'  => 'APP_USR-new',
            'refresh_token' => 'TG-new',
            'expires_in'    => 15552000,
        ];

        $this->givenTokenEndpointConfig();

        $this->httpClient->shouldReceive('post')
            ->once()
            ->with('https://api.mercadopago.com/oauth/token', Mockery::on(static function (array $options): bool {
                return ($options['form_params']['grant_type'] ?? null) === 'refresh_token'
                    && ($options['form_params']['refresh_token'] ?? null) === 'TG-old';
            }))
            ->andReturn(new Response(200, [], json_encode($tokenData)));

        $result = $this->service->refreshAccessToken('TG-old');

        $this->assertSame('APP_USR-new', $result['access_token']);
        $this->assertSame('TG-new', $result['refresh_token']);
    }

    public function test_refresh_access_token_maps_invalid_grant_to_a_terminal_exception(): void
    {
        $this->givenTokenEndpointConfig();
        $this->givenRefreshFailsWith(400, json_encode(['error' => 'invalid_grant', 'message' => 'invalid refresh token']));

        try {
            $this->service->refreshAccessToken('TG-dead');
            $this->fail('Expected MercadoPagoOAuthException');
        } catch (MercadoPagoOAuthException $e) {
            $this->assertSame('invalid_grant', $e->getMpErrorCode());
            $this->assertTrue($e->isTerminal());
            $this->assertFalse($e->isRetryable());
        }
    }

    public function test_refresh_access_token_maps_429_to_a_retryable_exception(): void
    {
        $this->givenTokenEndpointConfig();
        // Sin body JSON: el codigo tiene que salir del status 429 igual.
        $this->givenRefreshFailsWith(429, 'too many requests');

        try {
            $this->service->refreshAccessToken('TG-throttled');
            $this->fail('Expected MercadoPagoOAuthException');
        } catch (MercadoPagoOAuthException $e) {
            $this->assertSame('local_rate_limited', $e->getMpErrorCode());
            $this->assertTrue($e->isRetryable());
            $this->assertFalse($e->isTerminal());
        }
    }

    public function test_refresh_access_token_maps_a_connection_failure_to_neither_terminal_nor_retryable(): void
    {
        $this->givenTokenEndpointConfig();
        $this->logger->shouldReceive('error')->once();

        $this->httpClient->shouldReceive('post')
            ->once()
            ->andThrow(new \GuzzleHttp\Exception\ConnectException(
                'Connection refused',
                new Request('POST', 'https://api.mercadopago.com/oauth/token'),
            ));

        try {
            $this->service->refreshAccessToken('TG-unreachable');
            $this->fail('Expected MercadoPagoOAuthException');
        } catch (MercadoPagoOAuthException $e) {
            $this->assertNull($e->getMpErrorCode());
            $this->assertFalse($e->isTerminal());
            $this->assertFalse($e->isRetryable());
        }
    }

    private function givenTokenEndpointConfig(): void
    {
        $this->config->shouldReceive('get')->with('mercadopago.client_id')->andReturn('id');
        $this->config->shouldReceive('get')->with('mercadopago.client_secret')->andReturn('secret');
        $this->config->shouldReceive('get')->with('mercadopago.token_url')->andReturn('https://api.mercadopago.com/oauth/token');
    }

    private function givenRefreshFailsWith(int $status, string $body): void
    {
        $this->logger->shouldReceive('error')->once();

        $this->httpClient->shouldReceive('post')
            ->once()
            ->andThrow(new BadResponseException(
                'Client error',
                new Request('POST', 'https://api.mercadopago.com/oauth/token'),
                new Response($status, [], $body),
            ));
    }
}
