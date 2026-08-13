<?php

namespace App\Monitoring;

use Binafy\LaravelUserMonitoring\Contracts\MonitoringCondition;
use Illuminate\Http\Request;

/**
 * Decides what counts as a visit.
 *
 * VisitMonitoringMiddleware runs on every request it is attached to, and this
 * app is Livewire-driven: each Filament table sort, search keystroke and modal
 * open is its own HTTP round trip. Recording all of them turns
 * visits_monitoring into a keystroke log and buries the actual navigation.
 *
 * Filtering by path cannot work here — Livewire's URL prefix is obfuscated and
 * derived from the app key, so it changes whenever the key does. Headers and
 * the HTTP verb are stable, so this class matches on those instead.
 *
 * Registered in config/user-monitoring.php under visit_monitoring.conditions.
 * Every condition must return true for the visit to be recorded.
 */
class PageViewsOnly implements MonitoringCondition
{
    public function shouldMonitor(Request $request): bool
    {
        // A page view is a GET. POST/PATCH/DELETE are actions, and the ones
        // worth auditing are already covered: logins and logouts by
        // authentication monitoring, model changes by activitylog.
        if (! $request->isMethodSafe()) {
            return false;
        }

        // Livewire sends X-Livewire on its update calls regardless of verb.
        if ($request->hasHeader('X-Livewire')) {
            return false;
        }

        // Anything asking for JSON is a background fetch, not a person opening
        // a page — the log viewer polls its API this way.
        if ($request->expectsJson()) {
            return false;
        }

        // Browsers speculatively fetch links on hover. Counting those would
        // record pages nobody actually looked at.
        return ! in_array(
            $request->header('Sec-Purpose') ?? $request->header('Purpose'),
            ['prefetch', 'prefetch;prerender'],
            strict: true,
        );
    }
}
