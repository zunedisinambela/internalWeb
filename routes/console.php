<?php

use App\Console\Commands\PruneExports;
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

/*
 * Removes rendered cash book exports once their signed links have expired.
 *
 * Hourly rather than daily: the retention is measured in hours, so a daily run
 * would leave a copy of the book on disk for up to a day after its link died.
 * The same "nothing runs the scheduler on its own" caveat above applies here,
 * and the symptom is quieter — exports keep working, the files just never go.
 */
Schedule::command(PruneExports::class)
    ->hourly()
    ->withoutOverlapping();
