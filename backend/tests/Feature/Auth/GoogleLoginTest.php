<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Firebase\JWT\JWT;
use HiEvents\Models\AccountConfiguration;
use HiEvents\Models\User;
use HiEvents\Models\UserSocialIdentity;
use HiEvents\Services\Domain\Auth\SocialAuthNonceService;
use HiEvents\Services\Infrastructure\SocialAuth\Google\GoogleJwksProvider;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery;
use OpenSSLAsymmetricKey;
use Tests\TestCase;

/**
 * Exercises the full Google sign-in stack. Only Google's key endpoint is faked — tokens
 * are really signed and really verified.
 */
class GoogleLoginTest extends TestCase
{
    use DatabaseTransactions;

    private const GOOGLE_ROUTE = '/auth/google';
    private const COMPLETE_ROUTE = '/auth/google/complete-registration';
    private const CLIENT_ID = '1234567890-passix.apps.googleusercontent.com';
    private const KEY_ID = 'test-key-1';

    private OpenSSLAsymmetricKey $privateKey;

    protected function setUp(): void
    {
        parent::setUp();

        AccountConfiguration::firstOrCreate(['id' => 1], [
            'id' => 1,
            'name' => 'Default',
            'is_system_default' => true,
            'application_fees' => ['percentage' => 1.5, 'fixed' => 0],
        ]);

        $this->privateKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        config()->set('services.google.enabled', true);
        config()->set('services.google.client_id', self::CLIENT_ID);
        config()->set('services.google.issuers', ['https://accounts.google.com', 'accounts.google.com']);
        config()->set('services.google.leeway_seconds', 60);
        config()->set('services.google.registration_token_ttl_seconds', 900);
        config()->set('services.google.nonce_ttl_seconds', 600);

        $jwksProvider = Mockery::mock(GoogleJwksProvider::class);
        $jwksProvider->shouldReceive('getKeys')->andReturn([$this->buildJwk()]);
        $this->app->instance(GoogleJwksProvider::class, $jwksProvider);
    }

    public function test_unknown_google_account_is_asked_to_complete_registration(): void
    {
        $response = $this->postJson(self::GOOGLE_ROUTE, [
            'id_token' => $this->makeToken(['email' => 'newcomer@example.com']),
        ]);

        $response->assertSuccessful();
        $response->assertJsonPath('registration_required', true);
        $response->assertJsonPath('email', 'newcomer@example.com');
        $response->assertJsonStructure(['registration_token', 'email', 'first_name', 'last_name']);

        // No session is handed out until the account actually exists.
        $response->assertCookieMissing('token');
        $this->assertDatabaseMissing('users', ['email' => 'newcomer@example.com']);
    }

    public function test_completing_registration_creates_the_account_and_signs_the_user_in(): void
    {
        $registrationToken = $this->postJson(self::GOOGLE_ROUTE, [
            'id_token' => $this->makeToken(['email' => 'newcomer@example.com']),
        ])->json('registration_token');

        $response = $this->postJson(self::COMPLETE_ROUTE, [
            'registration_token' => $registrationToken,
            'business_name' => 'Productora Norte',
            'currency_code' => 'ARS',
            'timezone' => 'America/Argentina/Buenos_Aires',
        ]);

        $response->assertSuccessful();
        $response->assertCookie('token');
        $response->assertHeader('X-Auth-Token');
        $response->assertJsonStructure(['token', 'token_type', 'expires_in', 'user', 'accounts']);

        $this->assertDatabaseHas('users', ['email' => 'newcomer@example.com']);
        $this->assertDatabaseHas('accounts', ['name' => 'Productora Norte']);
        $this->assertDatabaseHas('user_social_identities', [
            'provider' => 'google',
            'provider_user_id' => 'google-sub-123',
            'email' => 'newcomer@example.com',
        ]);

        // Google already proved the address, so no confirmation round-trip is needed.
        $user = User::where('email', 'newcomer@example.com')->firstOrFail();
        $this->assertNotNull($user->email_verified_at);
        $this->assertNull($user->password);
    }

    /**
     * The account must be created under the address Google vouched for, never one the
     * request body smuggles in, or anyone could register on top of someone else's email.
     */
    public function test_request_body_cannot_override_the_identity_from_the_token(): void
    {
        $registrationToken = $this->postJson(self::GOOGLE_ROUTE, [
            'id_token' => $this->makeToken(['email' => 'newcomer@example.com']),
        ])->json('registration_token');

        $this->postJson(self::COMPLETE_ROUTE, [
            'registration_token' => $registrationToken,
            'business_name' => 'Productora Norte',
            'currency_code' => 'ARS',
            'email' => 'victim@example.com',
            'first_name' => 'Injected',
            'password' => 'injected-password',
            'is_email_verified' => true,
            'utm_raw' => ['email' => 'victim@example.com'],
        ])->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'newcomer@example.com']);
        $this->assertDatabaseMissing('users', ['email' => 'victim@example.com']);

        $user = User::where('email', 'newcomer@example.com')->firstOrFail();
        $this->assertNull($user->password, 'A smuggled password must never be set');
        $this->assertSame('Ada', $user->first_name);
    }

    public function test_registration_cannot_be_completed_with_a_forged_token(): void
    {
        $response = $this->postJson(self::COMPLETE_ROUTE, [
            'registration_token' => 'not-a-real-token',
            'business_name' => 'Productora Norte',
        ]);

        $response->assertStatus(401);
        $this->assertDatabaseMissing('accounts', ['name' => 'Productora Norte']);
    }

    public function test_returning_google_user_is_signed_in(): void
    {
        $user = User::factory()->withAccount()->create();

        UserSocialIdentity::create([
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_user_id' => 'google-sub-123',
            'email' => $user->email,
        ]);

        $response = $this->postJson(self::GOOGLE_ROUTE, [
            'id_token' => $this->makeToken(['email' => $user->email]),
        ]);

        $response->assertSuccessful();
        $response->assertCookie('token');
        $response->assertJsonPath('user.email', $user->email);
    }

    /**
     * A user who signed up with a password must be able to switch to Google without
     * ending up with a second, duplicate account.
     */
    public function test_existing_password_user_is_linked_rather_than_duplicated(): void
    {
        $user = User::factory()->withAccount()->create();

        $response = $this->postJson(self::GOOGLE_ROUTE, [
            'id_token' => $this->makeToken(['email' => $user->email]),
        ]);

        $response->assertSuccessful();
        $response->assertCookie('token');

        $this->assertDatabaseHas('user_social_identities', [
            'user_id' => $user->id,
            'provider' => 'google',
            'provider_user_id' => 'google-sub-123',
        ]);
        $this->assertSame(1, User::where('email', $user->email)->count());
    }

    /**
     * Google-only users have a NULL password. Nothing may ever authenticate against it —
     * an empty hash that compared equal would hand out every social account.
     */
    public function test_passwordless_user_cannot_sign_in_with_a_password(): void
    {
        $registrationToken = $this->postJson(self::GOOGLE_ROUTE, [
            'id_token' => $this->makeToken(['email' => 'newcomer@example.com']),
        ])->json('registration_token');

        $this->postJson(self::COMPLETE_ROUTE, [
            'registration_token' => $registrationToken,
            'business_name' => 'Productora Norte',
            'currency_code' => 'ARS',
        ])->assertSuccessful();

        $this->assertNull(User::where('email', 'newcomer@example.com')->firstOrFail()->password);

        // Long enough to clear request validation, so the credential check is what rejects it.
        foreach (['', 'password', 'passwordless', '00000000'] as $attempt) {
            $response = $this->postJson('/auth/login', [
                'email' => 'newcomer@example.com',
                'password' => $attempt,
            ]);

            $this->assertTrue(
                $response->getStatusCode() >= 400,
                "Password '{$attempt}' must not be accepted",
            );
            $response->assertCookieMissing('token');
            $response->assertHeaderMissing('X-Auth-Token');
        }
    }

    /**
     * The whole point of a server-issued nonce: a token captured in transit is spent the
     * moment its rightful owner uses it, so replaying it later gains an attacker nothing.
     */
    public function test_a_token_cannot_be_replayed(): void
    {
        $user = User::factory()->withAccount()->create();
        $idToken = $this->makeToken(['email' => $user->email]);

        $this->postJson(self::GOOGLE_ROUTE, ['id_token' => $idToken])->assertSuccessful();

        $replay = $this->postJson(self::GOOGLE_ROUTE, ['id_token' => $idToken]);

        $replay->assertStatus(401);
        $replay->assertCookieMissing('token');
        $replay->assertHeaderMissing('X-Auth-Token');
    }

    /**
     * A token is only accepted if it carries a nonce we handed out, so one obtained
     * outside our sign-in flow cannot be presented here.
     */
    public function test_rejects_a_nonce_we_never_issued(): void
    {
        $response = $this->postJson(self::GOOGLE_ROUTE, [
            'id_token' => $this->makeToken(['nonce' => 'never-issued-by-us']),
        ]);

        $response->assertStatus(401);
        $response->assertCookieMissing('token');
    }

    public function test_rejects_a_token_with_no_nonce(): void
    {
        $claims = [
            'iss' => 'https://accounts.google.com',
            'aud' => self::CLIENT_ID,
            'sub' => 'google-sub-123',
            'email' => 'organiser@example.com',
            'email_verified' => true,
            'iat' => time(),
            'exp' => time() + 3600,
        ];

        $this->postJson(self::GOOGLE_ROUTE, [
            'id_token' => JWT::encode($claims, $this->privateKey, 'RS256', self::KEY_ID),
        ])->assertStatus(401);
    }

    public function test_nonce_endpoint_issues_a_usable_value(): void
    {
        $response = $this->getJson('/auth/social/nonce');

        $response->assertSuccessful();
        $response->assertJsonStructure(['nonce']);
        $this->assertNotEmpty($response->json('nonce'));
    }

    public function test_rejects_a_token_minted_for_another_client(): void
    {
        $response = $this->postJson(self::GOOGLE_ROUTE, [
            'id_token' => $this->makeToken(['aud' => 'someone-elses-client-id']),
        ]);

        $response->assertStatus(401);
        $response->assertCookieMissing('token');
    }

    public function test_rejects_an_unverified_google_email(): void
    {
        $response = $this->postJson(self::GOOGLE_ROUTE, [
            'id_token' => $this->makeToken(['email_verified' => false]),
        ]);

        $response->assertStatus(401);
        $response->assertCookieMissing('token');
    }

    public function test_returns_forbidden_when_google_sign_in_is_disabled(): void
    {
        config()->set('services.google.enabled', false);

        $response = $this->postJson(self::GOOGLE_ROUTE, [
            'id_token' => $this->makeToken(),
        ]);

        $response->assertStatus(403);
    }

    public function test_requires_an_id_token(): void
    {
        $this->postJson(self::GOOGLE_ROUTE, [])->assertStatus(422);
    }

    public function test_is_rate_limited(): void
    {
        // The 'auth-social' limiter allows 10 requests per minute per IP.
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $this->postJson(self::GOOGLE_ROUTE, [
                'id_token' => $this->makeToken(['aud' => 'someone-elses-client-id']),
            ])->assertStatus(401);
        }

        $this->postJson(self::GOOGLE_ROUTE, [
            'id_token' => $this->makeToken(['aud' => 'someone-elses-client-id']),
        ])->assertStatus(429);
    }

    /**
     * Issues a nonce through the service rather than the endpoint, so that the shared
     * rate limiter does not interfere with tests that make many sign-in attempts. The
     * single-use consumption path is still the real one.
     */
    private function issuedNonce(): string
    {
        return app(SocialAuthNonceService::class)->issue();
    }

    private function makeToken(array $overrides = []): string
    {
        $claims = array_merge([
            'nonce' => $this->issuedNonce(),
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

        return JWT::encode($claims, $this->privateKey, 'RS256', self::KEY_ID);
    }

    private function buildJwk(): array
    {
        $details = openssl_pkey_get_details($this->privateKey);

        return [
            'kty' => 'RSA',
            'kid' => self::KEY_ID,
            'alg' => 'RS256',
            'use' => 'sig',
            'n' => rtrim(strtr(base64_encode($details['rsa']['n']), '+/', '-_'), '='),
            'e' => rtrim(strtr(base64_encode($details['rsa']['e']), '+/', '-_'), '='),
        ];
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
