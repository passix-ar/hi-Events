<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Auth\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\Enums\Role;
use Illuminate\Support\Collection;

/**
 * Which account a user is signing in to, and with what role.
 *
 * A null accountId means the user belongs to several accounts and has not picked one yet.
 */
final class AccountContextDTO extends BaseDataObject
{
    public function __construct(
        public readonly Collection $accounts,
        public readonly ?int       $accountId,
        public readonly ?Role      $role,
    )
    {
    }
}
