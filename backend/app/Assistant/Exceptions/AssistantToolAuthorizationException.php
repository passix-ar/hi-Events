<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Exceptions;

use HiEvents\Exceptions\BaseException;

/**
 * Raised when a tool receives an entity id that does not belong to the
 * organizer the conversation is scoped to. Never surfaces to the model
 * (it sees an opaque "not found") nor to the HTTP client.
 */
class AssistantToolAuthorizationException extends BaseException
{
}
