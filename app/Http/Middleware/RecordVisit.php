<?php

namespace App\Http\Middleware;

use Binafy\LaravelUserMonitoring\Middlewares\VisitMonitoringMiddleware;
use Closure;
use Illuminate\Http\Request;
use Throwable;

/**
 * Records a visit without letting the recording break the request.
 *
 * The package's middleware writes its row inline, so anything that makes the
 * insert fail — a missing table, a locked database, a full disk — turns every
 * page of the app into a 500. Monitoring must not be able to take down the
 * thing it monitors.
 *
 * The parent is given a closure that does nothing and returns null: it performs
 * its insert, calls that closure, and the return value is thrown away. Only
 * then is the real pipeline run, outside the try block. Wrapping
 * parent::handle($request, $next) directly would be wrong — an exception raised
 * by the application inside $next would be caught here and the pipeline run a
 * second time.
 *
 * Failures are reported rather than swallowed, so a monitoring outage shows up
 * in the log at /log-viewer instead of disappearing.
 */
class RecordVisit extends VisitMonitoringMiddleware
{
    public function handle(Request $request, Closure $next): mixed
    {
        try {
            parent::handle($request, fn (): null => null);
        } catch (Throwable $e) {
            report($e);
        }

        return $next($request);
    }
}
