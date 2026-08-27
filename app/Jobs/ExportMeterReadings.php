<?php

namespace App\Jobs;

use App\Reports\MeterReadingReport;

/**
 * Renders the meter log off the request.
 *
 * Everything this does lives on App\Jobs\ExportReport — the uniqueness lock,
 * the retention, the notification, the failure notice and the audit entry are
 * identical for every downloadable screen in this panel. A class of its own is
 * what gives this screen its own double-click guard: Laravel keys the
 * ShouldBeUnique lock on get_class($job), so an export here cannot cancel one
 * queued from another screen over rows that happen to share ids.
 */
class ExportMeterReadings extends ExportReport
{
    protected static string $report = MeterReadingReport::class;
}
