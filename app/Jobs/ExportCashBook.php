<?php

namespace App\Jobs;

use App\Reports\CashBook;

/**
 * Renders the cash book off the request.
 *
 * Everything this does lives on App\Jobs\ExportReport — the uniqueness lock,
 * the retention, the notification, the failure notice and the audit entry are
 * identical for every downloadable screen in this panel. What is left here is
 * the one thing that is not: which report gets rendered.
 *
 * It stays a class of its own rather than becoming a constructor argument for
 * two reasons. Laravel keys the ShouldBeUnique lock on get_class($job), so a
 * class per report is what keeps one screen's double-click guard from
 * cancelling another screen's export. And the constants the rest of the app
 * reads off it — DISK, DIRECTORY, RETENTION_HOURS — resolve through
 * inheritance, so App\Console\Commands\PruneExports and the tests that name
 * them did not have to move when the plumbing did.
 */
class ExportCashBook extends ExportReport
{
    protected static string $report = CashBook::class;
}
