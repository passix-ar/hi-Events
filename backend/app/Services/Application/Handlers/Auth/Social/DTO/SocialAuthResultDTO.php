<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Auth\Social\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\Services\Domain\Auth\DTO\LoginResponse;

/**
 * The outcome of a social sign in: either the user is now logged in, or they are new and
 * must supply the details the provider cannot give us before an account can be created.
 *
 * Build it with the named constructors — they are the only combinations that make sense.
 */
final class SocialAuthResultDTO extends BaseDataObject
{
    public function __construct(
        public readonly ?LoginResponse $loginResponse = null,
        public readonly ?string        $registrationToken = null,
        public readonly ?string        $email = null,
        public readonly ?string        $firstName = null,
        public readonly ?string        $lastName = null,
    )
    {
    }

    public static function authenticated(LoginResponse $loginResponse): self
    {
        return new self(loginResponse: $loginResponse);
    }

    public static function registrationRequired(
        string  $registrationToken,
        string  $email,
        ?string $firstName,
        ?string $lastName,
    ): self
    {
        return new self(
            registrationToken: $registrationToken,
            email: $email,
            firstName: $firstName,
            lastName: $lastName,
        );
    }

    public function requiresRegistration(): bool
    {
        return $this->loginResponse === null;
    }
}
