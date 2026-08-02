<?php

declare(strict_types=1);

namespace HiEvents\Exceptions\SocialAuth;

use HiEvents\Exceptions\BaseException;

/**
 * The ID token could not be trusted: bad signature, wrong issuer or audience,
 * expired, replayed nonce, or an unverified email address.
 */
class InvalidIdTokenException extends BaseException
{
}
