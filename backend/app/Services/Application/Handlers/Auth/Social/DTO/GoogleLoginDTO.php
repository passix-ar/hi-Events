<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Auth\Social\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

final class GoogleLoginDTO extends BaseDataObject
{
    public function __construct(
        public readonly string $idToken,
        public readonly ?int   $accountId = null,
    )
    {
    }
}
