<?php

namespace HiEvents\Helper;

use Carbon\Carbon;

class DateHelper
{
    /**
     * Written to the database, so the format must not depend on the locale:
     * Carbon::toString() is translated, and with an English app locale falling
     * back to Spanish it spells the month "Sep.", which Postgres rejects.
     */
    public static function convertToUTC(string $eventDate, string $userTimezone): string
    {
        return Carbon::parse($eventDate, $userTimezone)
            ->setTimezone('UTC')
            ->toDateTimeString();
    }

    /**
     * Re-parsed by callers (new Carbon(...)), so the offset is kept: ISO 8601
     * carries it and is not translated.
     */
    public static function convertFromUTC(string $eventDate, string $userTimezone): string
    {
        return Carbon::parse($eventDate, 'UTC')
            ->setTimezone($userTimezone)
            ->toIso8601String();
    }

    public static function utcDateIsPast(string $eventDate): bool
    {
        return Carbon::parse($eventDate, 'UTC')->isPast();
    }

    public static function utcDateIsFuture(string $eventDate): bool
    {
        return Carbon::parse($eventDate, 'UTC')->isFuture();
    }
}
