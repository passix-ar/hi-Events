<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\SocialAuth\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\Enums\SocialAuthProvider;

/**
 * A verified identity from a social provider. Only ever built by a verifier after the
 * provider's signature and claims have been checked — never from raw request input.
 */
final class SocialUserProfileDTO extends BaseDataObject
{
    public function __construct(
        public readonly SocialAuthProvider $provider,
        public readonly string             $providerUserId,
        public readonly string             $email,
        public readonly ?string            $firstName = null,
        public readonly ?string            $lastName = null,
        public readonly ?string            $locale = null,
        /** The nonce carried by the token, still to be checked against the ones we issued. */
        public readonly ?string            $nonce = null,
    )
    {
    }
}
