<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\SocialAuth\Google;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use HiEvents\DomainObjects\Enums\SocialAuthProvider;
use HiEvents\Exceptions\SocialAuth\InvalidIdTokenException;
use HiEvents\Exceptions\SocialAuth\SocialAuthDisabledException;
use HiEvents\Services\Infrastructure\SocialAuth\DTO\SocialUserProfileDTO;
use Illuminate\Config\Repository as Config;
use Psr\Log\LoggerInterface;
use stdClass;
use Throwable;

/**
 * Verifies a Google ID token and converts it into a trusted profile.
 *
 * The signature, `exp`, `iat` and `nbf` are checked by the JWT library against Google's
 * published keys; `iss`, `aud`, `nonce` and `email_verified` are checked here. A token
 * that fails any of these never reaches the rest of the application.
 */
class GoogleIdTokenVerifier
{
    public function __construct(
        private readonly Config            $config,
        private readonly GoogleJwksProvider $jwksProvider,
        private readonly LoggerInterface   $logger,
    )
    {
    }

    public function isEnabled(): bool
    {
        return (bool)$this->config->get('services.google.enabled')
            && !empty($this->config->get('services.google.client_id'));
    }

    /**
     * Establishes that the token is cryptographically valid and was minted for us.
     *
     * The nonce is only extracted here, not judged: whether it is one we issued and have
     * not already spent is a replay question, answered by the caller.
     *
     * @param string $idToken The raw ID token issued by Google Identity Services.
     *
     * @throws SocialAuthDisabledException
     * @throws InvalidIdTokenException
     */
    public function verify(string $idToken): SocialUserProfileDTO
    {
        if (!$this->isEnabled()) {
            throw new SocialAuthDisabledException(__('Signing in with Google is not available.'));
        }

        $claims = $this->decode($idToken);

        $this->assertIssuerIsGoogle($claims);
        $this->assertAudienceIsThisApplication($claims);
        $this->assertEmailIsPresentAndVerified($claims);
        $this->assertSubjectIsPresent($claims);

        return new SocialUserProfileDTO(
            provider: SocialAuthProvider::GOOGLE,
            providerUserId: (string)$claims->sub,
            email: strtolower((string)$claims->email),
            firstName: $this->nullIfBlank($claims->given_name ?? null),
            lastName: $this->nullIfBlank($claims->family_name ?? null),
            locale: $this->nullIfBlank($claims->locale ?? null),
            nonce: $this->nullIfBlank($claims->nonce ?? null),
        );
    }

    /**
     * @throws InvalidIdTokenException
     */
    private function decode(string $idToken): stdClass
    {
        $keys = $this->jwksProvider->getKeys($this->readKeyId($idToken));

        $previousLeeway = JWT::$leeway;
        JWT::$leeway = (int)$this->config->get('services.google.leeway_seconds');

        try {
            return JWT::decode($idToken, JWK::parseKeySet(['keys' => $keys]));
        } catch (Throwable $e) {
            $this->logger->info('Rejected Google ID token', ['reason' => $e->getMessage()]);

            throw new InvalidIdTokenException(
                __('We could not verify your Google sign in. Please try again.'),
                previous: $e,
            );
        } finally {
            JWT::$leeway = $previousLeeway;
        }
    }

    /**
     * Reads the unverified `kid` header so the key provider knows which key to look for.
     * Nothing is trusted from this value beyond selecting a candidate public key.
     */
    private function readKeyId(string $idToken): ?string
    {
        $segments = explode('.', $idToken);

        if (count($segments) !== 3) {
            return null;
        }

        try {
            $header = JWT::jsonDecode(JWT::urlsafeB64Decode($segments[0]));
        } catch (Throwable) {
            return null;
        }

        $keyId = $header->kid ?? null;

        return is_string($keyId) ? $keyId : null;
    }

    /**
     * @throws InvalidIdTokenException
     */
    private function assertIssuerIsGoogle(stdClass $claims): void
    {
        $issuers = (array)$this->config->get('services.google.issuers');

        if (!in_array($claims->iss ?? null, $issuers, true)) {
            throw new InvalidIdTokenException(
                __('We could not verify your Google sign in. Please try again.'),
            );
        }
    }

    /**
     * @throws InvalidIdTokenException
     */
    private function assertAudienceIsThisApplication(stdClass $claims): void
    {
        $clientId = (string)$this->config->get('services.google.client_id');

        // A token minted for a different OAuth client must never grant access here,
        // otherwise any site the user signed into could replay its token against us.
        if (!hash_equals($clientId, (string)($claims->aud ?? ''))) {
            throw new InvalidIdTokenException(
                __('We could not verify your Google sign in. Please try again.'),
            );
        }
    }

    /**
     * @throws InvalidIdTokenException
     */
    private function assertEmailIsPresentAndVerified(stdClass $claims): void
    {
        // We match users by email, so an unverified address would let anyone claim
        // someone else's Passix account by creating a Google account with their address.
        if (empty($claims->email) || ($claims->email_verified ?? false) !== true) {
            throw new InvalidIdTokenException(
                __('Your Google account does not have a verified email address.'),
            );
        }
    }

    /**
     * @throws InvalidIdTokenException
     */
    private function assertSubjectIsPresent(stdClass $claims): void
    {
        if (empty($claims->sub)) {
            throw new InvalidIdTokenException(
                __('We could not verify your Google sign in. Please try again.'),
            );
        }
    }

    private function nullIfBlank(?string $value): ?string
    {
        $trimmed = trim((string)$value);

        return $trimmed === '' ? null : $trimmed;
    }
}
