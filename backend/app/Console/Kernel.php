<?php

namespace HiEvents\Console;

use HiEvents\Jobs\Message\SendScheduledMessagesJob;
use HiEvents\Jobs\Waitlist\ProcessExpiredWaitlistOffersJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        $schedule->job(new SendScheduledMessagesJob)->everyMinute()->withoutOverlapping();
        $schedule->job(new ProcessExpiredWaitlistOffersJob)->everyMinute()->withoutOverlapping();
        // 16:00 UTC = 13:00 en Argentina: si la renovacion falla, el aviso llega
        // en horario laboral, y los fallos de esta tarea los resuelve una persona
        // (llamar al organizador para que reautorice, corregir una env var).
        $schedule->command('mercadopago:refresh-tokens')->dailyAt('16:00')->withoutOverlapping();
    }

    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        include base_path('routes/console.php');
    }
}
