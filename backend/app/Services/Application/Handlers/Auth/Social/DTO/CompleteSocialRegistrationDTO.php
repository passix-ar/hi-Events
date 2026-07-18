<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Auth\Social\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

/**
 * The details a social provider cannot give us, collected on the "finish your signup" step.
 *
 * Note there is no email field: the address comes from the signed registration token, so a
 * tampered request cannot create an account under somebody else's address.
 */
final class CompleteSocialRegistrationDTO extends BaseDataObject
{
    public function __construct(
        public readonly string  $registrationToken,
        public readonly string  $businessName,
        public readonly string  $locale,
        public readonly ?string $timezone = null,
        public readonly ?string $currencyCode = null,
        public readonly bool    $marketingOptIn = false,
        public readonly ?array  $utmData = null,
    )
    {
    }
}
