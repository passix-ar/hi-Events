<?php

namespace HiEvents\Services\Domain\Payment\MercadoPago;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use HiEvents\Exceptions\MercadoPago\MercadoPagoOAuthException;
use Illuminate\Config\Repository as Config;
use Psr\Log\LoggerInterface;

class MercadoPagoOAuthService
{
    public function __construct(
        private readonly Config          $config,
        private readonly Client         $httpClient,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function buildAuthorizationUrl(int $accountId): string
    {
        $params = http_build_query([
            'client_id'     => $this->config->get('mercadopago.client_id'),
            'response_type' => 'code',
            'platform_id'   => 'mp',
            'state'         => $this->encodeState($accountId),
            'redirect_uri'  => $this->config->get('mercadopago.redirect_uri'),
        ]);

        return $this->config->get('mercadopago.auth_url') . '?' . $params;
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
                    'client_id'     => $this->config->get('mercadopago.client_id'),
                    'client_secret' => $this->config->get('mercadopago.client_secret'),
                    'grant_type'    => 'authorization_code',
                    'code'          => $code,
                    'redirect_uri'  => $this->config->get('mercadopago.redirect_uri'),
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

    public function decodeState(string $state): int
    {
        $decoded = base64_decode($state, true);
        if ($decoded === false) {
            throw new MercadoPagoOAuthException(__('Invalid OAuth state parameter.'));
        }

        return (int) $decoded;
    }

    private function encodeState(int $accountId): string
    {
        return base64_encode((string) $accountId);
    }
}
