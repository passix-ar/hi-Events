<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Infrastructure\SocialAuth\Google;

use Firebase\JWT\JWT;
use HiEvents\DomainObjects\Enums\SocialAuthProvider;
use HiEvents\Exceptions\SocialAuth\InvalidIdTokenException;
use HiEvents\Exceptions\SocialAuth\SocialAuthDisabledException;
use HiEvents\Services\Infrastructure\SocialAuth\Google\GoogleIdTokenVerifier;
use HiEvents\Services\Infrastructure\SocialAuth\Google\GoogleJwksProvider;
use Illuminate\Config\Repository as Config;
use Mockery;
use Mockery\MockInterface;
use OpenSSLAsymmetricKey;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * Signs real tokens with a throwaway RSA key so signature verification is genuinely
 * exercised rather than mocked away.
 */
class GoogleIdTokenVerifierTest extends TestCase
{
    private const CLIENT_ID = '1234567890-passix.apps.googleusercontent.com';
    private const KEY_ID = 'test-key-1';

    private OpenSSLAsymmetricKey $privateKey;
    private array $jwks;
    private GoogleJwksProvider|MockInterface $jwksProvider;
    private LoggerInterface|MockInterface $logger;
    private GoogleIdTokenVerifier $verifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->privateKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        $this->jwks = [$this->buildJwk($this->privateKey, self::KEY_ID)];

        $this->jwksProvider = Mockery::mock(GoogleJwksProvider::class);
        $this->jwksProvider->shouldReceive('getKeys')->andReturn($this->jwks)->byDefault();

        $this->logger = Mockery::mock(LoggerInterface::class);
        $this->logger->shouldReceive('info')->byDefault();

        $this->verifier = new GoogleIdTokenVerifier(
            $this->buildConfig(),
            $this->jwksProvider,
            $this->logger,
        );
    }

    public function test_verifies_a_valid_token(): void
    {
        $profile = $this->verifier->verify($this->makeToken());

        $this->assertSame(SocialAuthProvider::GOOGLE, $profile->provider);
        $this->assertSame('google-sub-123', $profile->providerUserId);
        $this->assertSame('organiser@example.com', $profile->email);
        $this->assertSame('Ada', $profile->firstName);
        $this->assertSame('Lovelace', $profile->lastName);
    }

    public function test_lowercases_the_email(): void
    {
        $profile = $this->verifier->verify($this->makeToken(['email' => 'Organiser@Example.COM']));

        $this->assertSame('organiser@example.com', $profile->email);
    }

    /**
     * Without this check, an ID token minted for any other Google OAuth client could be
     * replayed against Passix to sign in as that user.
     */
    public function test_rejects_a_token_issued_for_another_client(): void
    {
        $this->expectException(InvalidIdTokenException::class);

        $this->verifier->verify($this->makeToken(['aud' => 'someone-elses-client-id']));
    }

    public function test_rejects_an_untrusted_issuer(): void
    {
        $this->expectException(InvalidIdTokenException::class);

        $this->verifier->verify($this->makeToken(['iss' => 'https://evil.example.com']));
    }

    public function test_rejects_an_expired_token(): void
    {
        $this->expectException(InvalidIdTokenException::class);

        $this->verifier->verify($this->makeToken([
            'iat' => time() - 7200,
            'exp' => time() - 3600,
        ]));
    }

    /**
     * Users are matched by email, so an unverified address would let anyone register a
     * Google account with a victim's address and take over their Passix account.
     */
    public function test_rejects_an_unverified_email(): void
    {
        $this->expectException(InvalidIdTokenException::class);

        $this->verifier->verify($this->makeToken(['email_verified' => false]));
    }

    public function test_rejects_a_token_with_no_email(): void
    {
        $this->expectException(InvalidIdTokenException::class);

        $this->verifier->verify($this->makeToken(['email' => null]));
    }

    /**
     * The verifier reports the nonce but does not judge it: deciding whether it is one we
     * issued and have not spent is a replay question, settled against our own store.
     */
    public function test_exposes_the_nonce_from_the_token(): void
    {
        $profile = $this->verifier->verify($this->makeToken(['nonce' => 'issued-by-us']));

        $this->assertSame('issued-by-us', $profile->nonce);
    }

    public function test_reports_a_missing_nonce_as_null(): void
    {
        $this->assertNull($this->verifier->verify($this->makeToken())->nonce);
    }

    public function test_rejects_a_token_signed_by_an_unknown_key(): void
    {
        $attackerKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        $forged = JWT::encode($this->claims(), $attackerKey, 'RS256', self::KEY_ID);

        $this->expectException(InvalidIdTokenException::class);

        $this->verifier->verify($forged);
    }

    public function test_rejects_malformed_input(): void
    {
        $this->expectException(InvalidIdTokenException::class);

        $this->verifier->verify('not-a-jwt');
    }

    public function test_throws_when_google_sign_in_is_disabled(): void
    {
        $verifier = new GoogleIdTokenVerifier(
            $this->buildConfig(enabled: false),
            $this->jwksProvider,
            $this->logger,
        );

        $this->expectException(SocialAuthDisabledException::class);

        $verifier->verify($this->makeToken());
    }

    public function test_is_disabled_when_no_client_id_is_configured(): void
    {
        $verifier = new GoogleIdTokenVerifier(
            $this->buildConfig(clientId: ''),
            $this->jwksProvider,
            $this->logger,
        );

        $this->assertFalse($verifier->isEnabled());
    }

    private function buildConfig(bool $enabled = true, ?string $clientId = self::CLIENT_ID): Config
    {
        return new Config([
            'services' => [
                'google' => [
                    'enabled' => $enabled,
                    'client_id' => $clientId,
                    'issuers' => ['https://accounts.google.com', 'accounts.google.com'],
                    'leeway_seconds' => 60,
                ],
            ],
        ]);
    }

    private function claims(array $overrides = []): array
    {
        return array_merge([
            'iss' => 'https://accounts.google.com',
            'aud' => self::CLIENT_ID,
            'sub' => 'google-sub-123',
            'email' => 'organiser@example.com',
            'email_verified' => true,
            'given_name' => 'Ada',
            'family_name' => 'Lovelace',
            'iat' => time(),
            'exp' => time() + 3600,
        ], $overrides);
    }

    private function makeToken(array $overrides = []): string
    {
        return JWT::encode($this->claims($overrides), $this->privateKey, 'RS256', self::KEY_ID);
    }

    private function buildJwk(OpenSSLAsymmetricKey $key, string $keyId): array
    {
        $details = openssl_pkey_get_details($key);

        return [
            'kty' => 'RSA',
            'kid' => $keyId,
            'alg' => 'RS256',
            'use' => 'sig',
            'n' => $this->base64UrlEncode($details['rsa']['n']),
            'e' => $this->base64UrlEncode($details['rsa']['e']),
        ];
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
