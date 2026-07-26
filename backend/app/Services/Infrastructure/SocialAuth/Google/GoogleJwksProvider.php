<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\SocialAuth\Google;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use HiEvents\Exceptions\SocialAuth\InvalidIdTokenException;
use Illuminate\Config\Repository as Config;
use Illuminate\Contracts\Cache\Repository as Cache;
use JsonException;
use Psr\Log\LoggerInterface;

/**
 * Supplies Google's public signing keys, cached between requests.
 *
 * Google rotates these keys regularly, so callers pass the token's `kid` and we refetch
 * on a miss. Refetching is driven by an unknown key id rather than by any signature
 * failure, so a flood of garbage tokens cannot turn this into a request amplifier.
 */
class GoogleJwksProvider
{
    private const CACHE_KEY = 'social_auth:google:jwks';

    public function __construct(
        private readonly Config          $config,
        private readonly Client          $httpClient,
        private readonly Cache           $cache,
        private readonly LoggerInterface $logger,
    )
    {
    }

    /**
     * @return array<int, array<string, mixed>> The raw JWKS "keys" array.
     * @throws InvalidIdTokenException When Google's keys cannot be retrieved.
     */
    public function getKeys(?string $keyId): array
    {
        $cached = $this->cache->get(self::CACHE_KEY);

        // A token without a kid can never match a fresher key either, so the cached set
        // is as good as a refetch — and garbage tokens must not drive requests to Google.
        if ($cached !== null && ($keyId === null || $this->containsKeyId($cached, $keyId))) {
            return $cached;
        }

        $keys = $this->fetchKeys();

        $this->cache->put(
            key: self::CACHE_KEY,
            value: $keys,
            ttl: $this->config->get('services.google.jwks_cache_ttl_seconds'),
        );

        return $keys;
    }

    /**
     * @return array<int, array<string, mixed>>
     * @throws InvalidIdTokenException
     */
    private function fetchKeys(): array
    {
        try {
            $response = $this->httpClient->get($this->config->get('services.google.jwks_url'));

            $body = json_decode(
                json: $response->getBody()->getContents(),
                associative: true,
                flags: JSON_THROW_ON_ERROR,
            );
        } catch (GuzzleException|JsonException $e) {
            $this->logger->error('Failed to fetch Google JWKS', ['error' => $e->getMessage()]);

            throw new InvalidIdTokenException(
                __('We could not reach Google to verify your sign in. Please try again.'),
                previous: $e,
            );
        }

        if (empty($body['keys']) || !is_array($body['keys'])) {
            $this->logger->error('Google JWKS response contained no keys');

            throw new InvalidIdTokenException(
                __('We could not reach Google to verify your sign in. Please try again.'),
            );
        }

        return $body['keys'];
    }

    /**
     * @param array<int, array<string, mixed>> $keys
     */
    private function containsKeyId(array $keys, ?string $keyId): bool
    {
        if ($keyId === null) {
            return false;
        }

        foreach ($keys as $key) {
            if (($key['kid'] ?? null) === $keyId) {
                return true;
            }
        }

        return false;
    }
}
