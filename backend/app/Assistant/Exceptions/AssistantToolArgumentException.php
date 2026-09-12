<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Exceptions;

use HiEvents\Exceptions\BaseException;

/**
 * The model passed arguments that failed validation. The message is safe to
 * echo back to the model so it can correct itself.
 */
class AssistantToolArgumentException extends BaseException
{
}
