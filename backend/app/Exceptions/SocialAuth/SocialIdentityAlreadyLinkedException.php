<?php

declare(strict_types=1);

namespace HiEvents\Exceptions\SocialAuth;

use HiEvents\Exceptions\BaseException;

/**
 * The provider account is already linked to a different Passix user. Re-linking it
 * would hand one person's events to another, so we refuse and ask them to contact support.
 */
class SocialIdentityAlreadyLinkedException extends BaseException
{
}
