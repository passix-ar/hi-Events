<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Auth;

use HiEvents\DomainObjects\Enums\SocialAuthProvider;
use HiEvents\Exceptions\SocialAuth\InvalidIdTokenException;
use HiEvents\Services\Infrastructure\SocialAuth\DTO\SocialUserProfileDTO;
use Illuminate\Config\Repository as Config;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Contracts\Encryption\Encrypter;
use Throwable;

/**
 * Carries a verified social profile across the "finish your signup" step.
 *
 * The profile is encrypted with the application key rather than held in a session, so the
 * SSR frontend stays stateless. Because the payload is authenticated, the client cannot
 * swap in a different email and register as somebody else.
 */
readonly class SocialRegistrationTokenService
{
    public function __construct(
        private Encrypter $encrypter,
        private Config    $config,
    )
    {
    }

    public function encode(SocialUserProfileDTO $profile): string
    {
        return $this->encrypter->encrypt([
            'provider' => $profile->provider->value,
            'provider_user_id' => $profile->providerUserId,
            'email' => $profile->email,
            'first_name' => $profile->firstName,
            'last_name' => $profile->lastName,
            'locale' => $profile->locale,
            'issued_at' => time(),
        ]);
    }

    /**
     * @throws InvalidIdTokenException When the token is forged, corrupt or expired.
     */
    public function decode(string $token): SocialUserProfileDTO
    {
        try {
            $payload = $this->encrypter->decrypt($token);
        } catch (DecryptException $e) {
            throw new InvalidIdTokenException($this->expiredMessage(), previous: $e);
        }

        if (!is_array($payload) || !isset($payload['issued_at'], $payload['provider'], $payload['provider_user_id'], $payload['email'])) {
            throw new InvalidIdTokenException($this->expiredMessage());
        }

        if ($this->hasExpired((int)$payload['issued_at'])) {
            throw new InvalidIdTokenException($this->expiredMessage());
        }

        try {
            $provider = SocialAuthProvider::from($payload['provider']);
        } catch (Throwable $e) {
            throw new InvalidIdTokenException($this->expiredMessage(), previous: $e);
        }

        return new SocialUserProfileDTO(
            provider: $provider,
            providerUserId: (string)$payload['provider_user_id'],
            email: (string)$payload['email'],
            firstName: $payload['first_name'] ?? null,
            lastName: $payload['last_name'] ?? null,
            locale: $payload['locale'] ?? null,
        );
    }

    private function hasExpired(int $issuedAt): bool
    {
        $ttl = (int)$this->config->get('services.google.registration_token_ttl_seconds');

        return (time() - $issuedAt) > $ttl;
    }

    private function expiredMessage(): string
    {
        return __('Your sign up session has expired. Please start again.');
    }
}
