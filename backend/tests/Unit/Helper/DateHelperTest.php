<?php

namespace Tests\Unit\Helper;

use Carbon\Carbon;
use HiEvents\Helper\DateHelper;
use Tests\TestCase;

class DateHelperTest extends TestCase
{
    public function test_convert_to_utc_uses_a_fixed_database_format(): void
    {
        // 20:00 in Buenos Aires (UTC-3) is 23:00 UTC. The format is the one
        // Postgres always accepts, whatever the app locale says about months.
        $this->assertSame('2026-03-10 23:00:00', DateHelper::convertToUTC('2026-03-10 20:00', 'America/Argentina/Buenos_Aires'));
    }

    public function test_convert_from_utc_keeps_the_offset_and_is_not_translated(): void
    {
        $this->assertSame('2026-03-10T20:00:00-03:00', DateHelper::convertFromUTC('2026-03-10 23:00:00', 'America/Argentina/Buenos_Aires'));
    }

    public function test_formats_do_not_change_with_the_locale(): void
    {
        // The bug: toString() borrowed the Spanish "sep." when the app ran in
        // English with a Spanish fallback. Neither helper may depend on it now.
        foreach (['en', 'es', 'pt_BR'] as $locale) {
            Carbon::setLocale($locale);
            $this->assertSame('2026-09-14 17:53:27', DateHelper::convertToUTC('2026-09-14 17:53:27', 'UTC'), $locale);
            $this->assertSame('2026-09-14T14:53:27-03:00', DateHelper::convertFromUTC('2026-09-14 17:53:27', 'America/Argentina/Buenos_Aires'), $locale);
        }
    }

    public function test_the_round_trip_is_lossless(): void
    {
        $utc = DateHelper::convertToUTC('2026-09-14 14:53:27', 'America/Argentina/Buenos_Aires');
        $back = new Carbon(DateHelper::convertFromUTC($utc, 'America/Argentina/Buenos_Aires'));

        $this->assertSame('2026-09-14 14:53:27', $back->format('Y-m-d H:i:s'));
    }
}
