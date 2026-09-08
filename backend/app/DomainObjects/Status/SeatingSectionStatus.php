<?php

namespace HiEvents\DomainObjects\Status;

use HiEvents\DomainObjects\Enums\BaseEnum;

enum SeatingSectionStatus
{
    use BaseEnum;

    case ACTIVE;
    case INACTIVE;
}
