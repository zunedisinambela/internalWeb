<?php

use App\Console\Commands\PruneMonitoring;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Applies the retention set at /admin/monitoring.
 *
 * Nothing runs this on its own: `php artisan dev` starts serve, queue:listen,
 * pail and vite, but no scheduler. It needs `php artisan schedule:work` locally
 * or the usual once-a-minute cron entry on a server:
 *
 *     * * * * * cd /path-to-app && php artisan schedule:run >> /dev/null 2>&1
 *
 * The settings screen shows when this last ran, so a scheduler that was never
 * started is visible there rather than silently keeping data forever.
 */
Schedule::command(PruneMonitoring::class)
    ->dailyAt('03:00')
    ->withoutOverlapping();
