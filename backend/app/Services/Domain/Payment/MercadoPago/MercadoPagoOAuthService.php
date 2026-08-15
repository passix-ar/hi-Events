<?php

// Added by Passix on 2026-05-25: MercadoPago Marketplace integration.

namespace HiEvents\Services\Domain\Payment\MercadoPago;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use HiEvents\Exceptions\MercadoPago\MercadoPagoOAuthException;
use Illuminate\Config\Repository as Config;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Psr\Log\LoggerInterface;

class MercadoPagoOAuthService
{
    private const STATE_TTL_SECONDS = 900;

    public function __construct(
        private readonly Config $config,
        private readonly Client $httpClient,
        private readonly LoggerInterface $logger,
        private readonly Encrypter $encrypter,
    ) {}

    public function buildAuthorizationUrl(int $accountId): string
    {
        $params = http_build_query([
            'client_id' => $this->config->get('mercadopago.client_id'),
            'response_type' => 'code',
            'platform_id' => 'mp',
            'state' => $this->encodeState($accountId),
            'redirect_uri' => $this->config->get('mercadopago.redirect_uri'),
        ]);

        return $this->config->get('mercadopago.auth_url').'?'.$params;
    }

    /**
     * Exchange OAuth authorization code for access token.
     *
     * @throws MercadoPagoOAuthException
     */
    public function exchangeCodeForToken(string $code): array
    {
        try {
            $response = $this->httpClient->post($this->config->get('mercadopago.token_url'), [
                'form_params' => [
                    'client_id' => $this->config->get('mercadopago.client_id'),
                    'client_secret' => $this->config->get('mercadopago.client_secret'),
                    'grant_type' => 'authorization_code',
                    'code' => $code,
                    'redirect_uri' => $this->config->get('mercadopago.redirect_uri'),
                ],
            ]);

            return json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
        } catch (GuzzleException $e) {
            $this->logger->error('MercadoPago OAuth token exchange failed', [
                'error' => $e->getMessage(),
            ]);
            throw new MercadoPagoOAuthException(
                __('Failed to connect MercadoPago account. Please try again.'),
                previous: $e,
            );
        }
    }

    /**
     * Exchange the stored refresh token for a fresh access/refresh token pair.
     *
     * MercadoPago refresh tokens are single-use: once this call succeeds the old
     * pair is dead, so the caller must persist the new one immediately.
     *
     * @throws MercadoPagoOAuthException
     */
    public function refreshAccessToken(string $refreshToken): array
    {
        try {
            $response = $this->httpClient->post($this->config->get('mercadopago.token_url'), [
                'form_params' => [
                    'client_id' => $this->config->get('mercadopago.client_id'),
                    'client_secret' => $this->config->get('mercadopago.client_secret'),
                    'grant_type' => 'refresh_token',
                    'refresh_token' => $refreshToken,
                ],
            ]);

            return json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
        } catch (GuzzleException $e) {
            $this->logger->error('MercadoPago token refresh failed', [
                'error' => $e->getMessage(),
            ]);
            throw new MercadoPagoOAuthException(
                __('Failed to refresh MercadoPago token.'),
                previous: $e,
            );
        }
    }

    /**
     * Decode and validate the OAuth state.
     *
     * The state is authenticated-encrypted (AES-256 + HMAC) with the app key, so it
     * cannot be forged: an attacker cannot craft a valid state pointing at another
     * organizer's account. It also carries a timestamp and is rejected once stale,
     * limiting replay.
     *
     * @throws MercadoPagoOAuthException
     */
    public function decodeState(string $state): int
    {
        try {
            $decoded = $this->encrypter->decrypt($this->fromUrlSafe($state));
        } catch (DecryptException $e) {
            throw new MercadoPagoOAuthException(__('Invalid OAuth state parameter.'), previous: $e);
        }

        if (! is_array($decoded) || ! isset($decoded['account_id'], $decoded['ts'])) {
            throw new MercadoPagoOAuthException(__('Invalid OAuth state parameter.'));
        }

        if (time() - (int) $decoded['ts'] > self::STATE_TTL_SECONDS) {
            throw new MercadoPagoOAuthException(__('Your MercadoPago connection request expired. Please try again.'));
        }

        return (int) $decoded['account_id'];
    }

    private function encodeState(int $accountId): string
    {
        return $this->toUrlSafe($this->encrypter->encrypt([
            'account_id' => $accountId,
            'ts' => time(),
        ]));
    }

    /**
     * Make the encrypted blob URL-safe so it survives the OAuth redirect round-trip
     * through MercadoPago without any character-encoding ambiguity.
     */
    private function toUrlSafe(string $value): string
    {
        return rtrim(strtr($value, '+/', '-_'), '=');
    }

    private function fromUrlSafe(string $value): string
    {
        $restored = strtr($value, '-_', '+/');
        $padding = strlen($restored) % 4;

        return $padding === 0 ? $restored : $restored.str_repeat('=', 4 - $padding);
    }
}
