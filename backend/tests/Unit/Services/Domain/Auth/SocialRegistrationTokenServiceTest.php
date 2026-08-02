<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Domain\Auth;

use HiEvents\DomainObjects\Enums\SocialAuthProvider;
use HiEvents\Exceptions\SocialAuth\InvalidIdTokenException;
use HiEvents\Services\Domain\Auth\SocialRegistrationTokenService;
use HiEvents\Services\Infrastructure\SocialAuth\DTO\SocialUserProfileDTO;
use Illuminate\Config\Repository as Config;
use Illuminate\Encryption\Encrypter;
use Tests\TestCase;

class SocialRegistrationTokenServiceTest extends TestCase
{
    private Encrypter $encrypter;
    private SocialRegistrationTokenService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->encrypter = new Encrypter(str_repeat('a', 32), 'AES-256-CBC');
        $this->service = $this->buildService();
    }

    public function test_round_trips_a_profile(): void
    {
        $decoded = $this->service->decode($this->service->encode($this->profile()));

        $this->assertSame(SocialAuthProvider::GOOGLE, $decoded->provider);
        $this->assertSame('google-sub-123', $decoded->providerUserId);
        $this->assertSame('organiser@example.com', $decoded->email);
        $this->assertSame('Ada', $decoded->firstName);
        $this->assertSame('Lovelace', $decoded->lastName);
    }

    /**
     * The email is the whole point of the token: if it could be swapped, anyone could
     * register an account under somebody else's address.
     */
    public function test_rejects_a_token_encrypted_with_a_different_key(): void
    {
        $foreignEncrypter = new Encrypter(str_repeat('b', 32), 'AES-256-CBC');

        $forged = $foreignEncrypter->encrypt([
            'provider' => 'google',
            'provider_user_id' => 'google-sub-123',
            'email' => 'victim@example.com',
            'issued_at' => time(),
        ]);

        $this->expectException(InvalidIdTokenException::class);

        $this->service->decode($forged);
    }

    public function test_rejects_an_expired_token(): void
    {
        $stale = $this->encrypter->encrypt([
            'provider' => 'google',
            'provider_user_id' => 'google-sub-123',
            'email' => 'organiser@example.com',
            'issued_at' => time() - 1000,
        ]);

        $this->expectException(InvalidIdTokenException::class);

        $this->service->decode($stale);
    }

    public function test_accepts_a_token_inside_its_window(): void
    {
        $fresh = $this->encrypter->encrypt([
            'provider' => 'google',
            'provider_user_id' => 'google-sub-123',
            'email' => 'organiser@example.com',
            'issued_at' => time() - 100,
        ]);

        $this->assertSame('organiser@example.com', $this->service->decode($fresh)->email);
    }

    public function test_rejects_garbage(): void
    {
        $this->expectException(InvalidIdTokenException::class);

        $this->service->decode('clearly-not-a-token');
    }

    public function test_rejects_a_payload_missing_required_fields(): void
    {
        $incomplete = $this->encrypter->encrypt(['issued_at' => time()]);

        $this->expectException(InvalidIdTokenException::class);

        $this->service->decode($incomplete);
    }

    public function test_rejects_an_unknown_provider(): void
    {
        $unknown = $this->encrypter->encrypt([
            'provider' => 'facebook',
            'provider_user_id' => 'fb-1',
            'email' => 'organiser@example.com',
            'issued_at' => time(),
        ]);

        $this->expectException(InvalidIdTokenException::class);

        $this->service->decode($unknown);
    }

    private function buildService(int $ttlSeconds = 900): SocialRegistrationTokenService
    {
        return new SocialRegistrationTokenService(
            $this->encrypter,
            new Config([
                'services' => ['google' => ['registration_token_ttl_seconds' => $ttlSeconds]],
            ]),
        );
    }

    private function profile(): SocialUserProfileDTO
    {
        return new SocialUserProfileDTO(
            provider: SocialAuthProvider::GOOGLE,
            providerUserId: 'google-sub-123',
            email: 'organiser@example.com',
            firstName: 'Ada',
            lastName: 'Lovelace',
        );
    }
}
