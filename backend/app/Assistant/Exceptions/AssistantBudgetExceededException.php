<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Exceptions;

use HiEvents\Exceptions\BaseException;

/**
 * The account has burned through its daily assistant token budget.
 */
class AssistantBudgetExceededException extends BaseException
{
}
