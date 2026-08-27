<?php

namespace App\Jobs;

use App\Models\User;
use App\Reports\Report;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfWrapper;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

/**
 * Renders a report to a file off the request, then tells the user where it is.
 *
 * One job for every downloadable screen in this panel. What differs between the
 * cash book, the sales log, the customer list and the meter log is which rows
 * are copied and what the columns are called, and all of that lives on an
 * App\Reports\Report. What is the same is everything below, and it is the part
 * that is easy to get subtly wrong — so it is written once.
 *
 * ## What is queued, and what is emphatically not
 *
 * This job is queued. The exports inside it are not — see the "Do not add
 * ShouldQueue" note on App\Exports\TransactionsExport. Laravel-Excel's own
 * queueing splits the query into chunks and runs each in its own job with its
 * own instance of everything, while a Report accumulates its totals as the rows
 * are walked: every chunk would restart the accumulation at zero and the file
 * would still look entirely plausible.
 *
 * Wrapping the whole render in one job sidesteps that completely. One job, one
 * Report, one pass over the rows in order — exactly the arrangement the
 * synchronous download had, only nobody is waiting on it.
 *
 * ## Why a list of ids rather than the query
 *
 * The obvious constructor argument is the filtered Builder the table already
 * has. It cannot be: an Eloquent builder holds its Connection, a Connection
 * holds a PDO handle, and PDO refuses to serialize — the dispatch dies with
 * "Serialization of 'PDO' is not allowed". Re-applying the caller's filters
 * inside the job is not available either, since they live on a Livewire
 * component that no longer exists by then.
 *
 * So the filtered set is resolved to primary keys at dispatch and travels as
 * plain data. Two consequences worth knowing: the payload grows with the
 * selection, and a row deleted between dispatch and render simply is not in the
 * file. The second one is honest — the alternative is a file that claims to
 * hold a row that no longer exists.
 *
 * ## The file is a read surface
 *
 * A finished export is a complete copy of data every screen in this panel gates
 * by policy, so it goes to the private `local` disk and is reached only through
 * a signed, expiring URL — the same protection receipts get, with the same
 * limit: within its window the link works for whoever holds it.
 * RETENTION_HOURS bounds that window and the file's life together, and
 * App\Console\Commands\PruneExports is what actually removes it.
 *
 * ## One render per request, not one per click
 *
 * The button is a button, so it gets clicked twice. Without a guard each click
 * is its own job: two full copies on disk, two audit entries for one act, and
 * two notifications offering the same thing.
 *
 * uniqueId() keys on the *request* — who asked, which format, and which rows —
 * rather than on the user or the format alone. That is the narrow reading on
 * purpose. A key of userId.format would also swallow the legitimate case:
 * filter the screen differently, click again, and the second export is silently
 * discarded while the screen says it is being processed. Including the row set
 * means only a genuine repeat of the same request is refused.
 *
 * The report is *not* in the key and does not need to be: Laravel's
 * UniqueLock::getKey() prefixes get_class($job), so each subclass already has
 * its own lock namespace. An export of sales 1-3 cannot cancel an export of
 * transactions 1-3.
 *
 * Because the key *is* the request, the "sedang diproses" message the action
 * flashes stays true for the discarded click as well — an export of exactly
 * those rows in exactly that format really is on its way. That is what makes it
 * safe to drop the duplicate without telling anyone.
 */
abstract class ExportReport implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const DISK = 'local';

    public const DIRECTORY = 'exports';

    /**
     * How long a finished file lives, and therefore how long its link works.
     *
     * One constant for both on purpose: a link that outlives its file 404s, and
     * a file that outlives its link is a copy of the data nobody can reach and
     * nothing will clean up.
     */
    public const RETENTION_HOURS = 24;

    /**
     * The report this job renders.
     *
     * A class-string rather than an instance, because the job is serialized:
     * a Report holds an Eloquent Builder, which is exactly what cannot travel
     * on a queue. It is rebuilt from the ids on the worker.
     *
     * @var class-string<Report>
     */
    protected static string $report;

    /**
     * A retry would write a second file and a second audit entry for one act.
     * Rendering is deterministic — a failure here is a bad query, a missing
     * font or an exhausted memory limit, and none of those get better on a
     * second attempt.
     */
    public int $tries = 1;

    /** A long report through phpspreadsheet is slower than the default 60s. */
    public int $timeout = 600;

    /**
     * How long the uniqueness lock survives a worker that dies without
     * releasing it — a SIGKILL, an OOM, a container replaced mid-render.
     *
     * It has to be longer than $timeout, or a job still rendering loses its
     * lock and a second click queues a duplicate after all. Bounded rather than
     * left at the default 0, which never expires: a lock nothing releases would
     * refuse that user's exports of that row set forever, with no error and
     * nothing on screen to explain it.
     */
    public int $uniqueFor = 900;

    /**
     * @param  array<int, int>  $ids  primary keys of the filtered set, in no
     *                                particular order — the Report imposes its own
     * @param  array<string, mixed>  $filters  what was on screen, for the audit entry
     */
    public function __construct(
        public readonly array $ids,
        public readonly string $format,
        public readonly int $userId,
        public readonly string $fileName,
        public readonly array $filters = [],
    ) {}

    /**
     * Who asked, for what, over which rows.
     *
     * The ids are sorted first. They arrive in whatever order the filtered
     * query happened to return them — the actions call reorder(), so there is
     * no ORDER BY at all and the database is free to hand the same set back in
     * a different order on a second run. Hashing them unsorted would produce a
     * different key for the same request, and the guard would do nothing
     * whatsoever without failing.
     *
     * md5 rather than a longer digest because this is a cache key, not a
     * signature: nothing here depends on an attacker being unable to construct
     * a collision, and the worst one could do is drop a duplicate export. The
     * digest is 32 characters whatever the selection's size — what grows with
     * it is the ids payload, which the job carries either way.
     */
    public function uniqueId(): string
    {
        $ids = $this->ids;

        sort($ids);

        return $this->userId.':'.$this->format.':'.md5(implode(',', $ids));
    }

    /**
     * The leading part of a file name for this report, stamped with the moment
     * it was asked for.
     *
     * Named at dispatch rather than at render, so the timestamp is when the
     * report was requested — the moment the user can point at — rather than
     * whenever a worker happened to pick the job up.
     */
    public static function fileName(string $extension): string
    {
        return static::$report::slug().'-'.now()->format('Y-m-d-His').'.'.$extension;
    }

    /** @return class-string<Report> */
    public static function report(): string
    {
        return static::$report;
    }

    public function handle(): void
    {
        $user = User::find($this->userId);

        $report = static::$report::forIds($this->ids);

        $path = self::DIRECTORY.'/'.$this->fileName;

        match ($this->format) {
            'xlsx' => Excel::store($report->excel(), $path, self::DISK),
            'pdf' => Storage::disk(self::DISK)->put($path, $this->renderPdf($report, $user?->name)),
            default => throw new \InvalidArgumentException("Format ekspor tidak dikenal: {$this->format}"),
        };

        // After the render, not before: rowCount() is accumulated by the fold
        // and is only final once every line has been written.
        $this->audit($user, $report->rowCount());

        if ($user !== null) {
            $this->announce($user, $path, $report->rowCount());
        }
    }

    /**
     * Tells the user the render failed.
     *
     * Without this the failure reaches failed_jobs and the log and nothing
     * else — the user watches a notification that never arrives and has no way
     * to tell "still rendering" from "died twenty minutes ago".
     *
     * The message is deliberately opaque. A notification body is rendered
     * through sanitizeHtml() rather than escaped (see the Gotchas section of
     * CLAUDE.md), and an exception message can carry SQL, absolute paths and
     * column names. The detail goes to the log, where it is already gated by
     * the same rule as the panel.
     */
    public function failed(Throwable $e): void
    {
        Log::error('Ekspor '.static::$report::label().' gagal', [
            'file_name' => $this->fileName,
            'format' => $this->format,
            'user_id' => $this->userId,
            'exception' => $e,
        ]);

        $user = User::find($this->userId);

        if ($user === null) {
            return;
        }

        Notification::make()
            ->title('Ekspor '.static::$report::label().' gagal')
            ->body('Berkas '.$this->fileName.' tidak dapat dibuat. Coba lagi, dan hubungi admin bila berulang.')
            ->danger()
            ->sendToDatabase($user);
    }

    public static function expiresAt(): Carbon
    {
        return now()->addHours(self::RETENTION_HOURS);
    }

    /**
     * The link is minted here rather than at dispatch, because it has to expire
     * with the file and the file does not exist until now.
     */
    private function announce(User $user, string $path, int $rows): void
    {
        $url = Storage::disk(self::DISK)->temporaryUrl($path, self::expiresAt());

        Notification::make()
            ->title(static::$report::label().' siap diunduh')
            ->body($rows.' '.static::$report::unit().' · '.strtoupper($this->format)
                .' · tautan berlaku '.self::RETENTION_HOURS.' jam')
            ->success()
            ->actions([
                Action::make('download')
                    ->label('Unduh')
                    ->url($url, shouldOpenInNewTab: true)
                    ->markAsRead(),
            ])
            ->sendToDatabase($user);
    }

    private function renderPdf(Report $report, ?string $printedBy): string
    {
        $pdf = Pdf::loadView($report->view(), $report->viewData())
            ->setPaper(...$report->paper());

        $this->stampFooter($pdf, $printedBy);

        return $pdf->output();
    }

    /**
     * Writes the footer — who printed it, when, and "halaman n dari m" — onto
     * every page after rendering.
     *
     * It is not in the Blade template, because the total page count cannot be
     * expressed there. dompdf has no `pages` counter at all: nothing in its
     * source refers to one, so `counter(pages)` resolves to an unset counter
     * and prints 0. The widely-quoted `$PAGE_COUNT` is the other route, and it
     * needs `enable_php`, which executes any `<script type="text/php">` in the
     * document with full application privileges — see the PDF section of
     * CLAUDE.md. `page_text()` is neither: it is a PHP-side canvas call that
     * substitutes {PAGE_NUM} and {PAGE_COUNT} per page.
     *
     * Rendering first and then reaching for the canvas is the package's own
     * idiom — PDF::setEncryption() does exactly this. render() sets the
     * `rendered` flag, so the later output() does not redo the work.
     */
    private function stampFooter(PdfWrapper $pdf, ?string $printedBy): void
    {
        $pdf->render();

        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();

        $printed = 'Dicetak '.now()->translatedFormat('d F Y H:i').' WIB'
            .($printedBy === null ? '' : ' oleh '.$printedBy);

        $canvas->page_text(
            40,
            $canvas->get_height() - 34,
            $printed.'  ·  Halaman {PAGE_NUM} dari {PAGE_COUNT}',
            // Resolved through FontMetrics rather than named, because page_text
            // wants a font file rather than a family. `sans-serif` maps to
            // Helvetica — see the note on fonts in the Blade templates.
            $dompdf->getFontMetrics()->getFont('sans-serif'),
            8,
            [0.42, 0.45, 0.50],
        );
    }

    /**
     * One event for both formats, distinguished by a property.
     *
     * Downloading a report is a single act; the file extension is a detail of
     * it, not a different thing that happened. Filtering the log for "who took
     * a copy of the book" should not mean remembering to check two event keys.
     *
     * causedBy() is explicit because this no longer runs in the requester's
     * session — a queue worker has no authenticated user, so the entry would
     * otherwise be attributed to nobody.
     */
    private function audit(?User $user, int $rows): void
    {
        activity('monitoring')
            ->causedBy($user)
            ->event(static::$report::event())
            ->withProperties([
                'file_name' => $this->fileName,
                'format' => $this->format,
                'rows' => $rows,
                // Which subset left the panel. Without this the entry says a
                // copy was taken but not which pages.
                'filters' => $this->filters,
            ])
            ->log(static::$report::label().' diunduh sebagai '.strtoupper($this->format));
    }
}
