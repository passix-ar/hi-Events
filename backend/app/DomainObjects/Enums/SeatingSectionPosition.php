<?php

namespace HiEvents\DomainObjects\Enums;

/**
 * Where a section sits in the room, relative to the stage. Enough to draw side stalls and a
 * section behind the stage without giving every section free coordinates.
 */
enum SeatingSectionPosition
{
    use BaseEnum;

    case LEFT;
    case CENTER;
    case RIGHT;
    case BEHIND;
}
