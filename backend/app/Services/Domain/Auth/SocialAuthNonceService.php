<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Auth;

use Illuminate\Config\Repository as Config;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * Issues single-use nonces that bind an ID token to one sign-in attempt.
 *
 * The nonce has to originate here rather than in the browser: a value the client both
 * generates and echoes back proves nothing, because anyone holding the token can read the
 * nonce out of its (unencrypted) payload and send a matching one. Minting it server side
 * and consuming it on first use is what actually makes a captured token unusable twice.
 */
readonly class SocialAuthNonceService
{
    private const CACHE_PREFIX = 'social_auth:nonce:';

    public function __construct(
        private Cache  $cache,
        private Config $config,
    )
    {
    }

    public function issue(): string
    {
        $nonce = bin2hex(random_bytes(16));

        $this->cache->put(
            key: self::CACHE_PREFIX . $nonce,
            value: true,
            ttl: $this->ttlSeconds(),
        );

        return $nonce;
    }

    /**
     * Returns true only the first time a given nonce is presented.
     */
    public function consume(?string $nonce): bool
    {
        if ($nonce === null || $nonce === '') {
            return false;
        }

        $key = self::CACHE_PREFIX . $nonce;

        if (!$this->cache->has($key)) {
            return false;
        }

        // Deleting before the caller proceeds means a replay of the same token loses the
        // race rather than being accepted twice.
        return $this->cache->forget($key);
    }

    private function ttlSeconds(): int
    {
        return (int)$this->config->get('services.google.nonce_ttl_seconds');
    }
}
