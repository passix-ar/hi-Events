<?php

declare(strict_types=1);

namespace HiEvents\Assistant\Domain;

use Illuminate\Support\Carbon;

final class AssistantDates
{
    /**
     * A stored UTC timestamp as local wall time, "YYYY-MM-DD HH:MM" in the given
     * timezone. Null stays null; a value that does not parse is returned as is
     * rather than throwing inside a tool.
     */
    public static function local(?string $storedUtc, string $timezone): ?string
    {
        if ($storedUtc === null || $storedUtc === '') {
            return null;
        }

        try {
            return Carbon::parse($storedUtc, 'UTC')->setTimezone($timezone)->format('Y-m-d H:i');
        } catch (\Throwable) {
            return $storedUtc;
        }
    }
}
