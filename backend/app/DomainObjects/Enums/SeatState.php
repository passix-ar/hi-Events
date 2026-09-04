<?php

namespace HiEvents\DomainObjects\Enums;

enum SeatState
{
    use BaseEnum;

    case AVAILABLE;
    case HELD;
    case SOLD;
    case DISABLED;
}
