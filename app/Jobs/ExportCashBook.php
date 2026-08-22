<?php

namespace App\Jobs;

use App\Exports\TransactionsExport;
use App\Models\Transaction;
use App\Models\User;
use App\Reports\CashBook;
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
 * Renders the cash book to a file off the request, then tells the user where
 * it is.
 *
 * ## What is queued, and what is emphatically not
 *
 * This job is queued. The export inside it is not — see the "Do not add
 * ShouldQueue" note on App\Exports\TransactionsExport. Laravel-Excel's own
 * queueing splits the query into chunks and runs each in its own job with its
 * own instance of everything, and the running balance in App\Reports\CashBook
 * accumulates as the rows are walked: every chunk would restart the balance at
 * zero and the file would still look entirely plausible.
 *
 * Wrapping the whole render in one job sidesteps that completely. One job, one
 * CashBook, one pass over the rows in order — exactly the arrangement the
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
 * plain data. Two consequences worth knowing: the payload grows with the book,
 * and a row deleted between dispatch and render simply is not in the file. The
 * second one is honest — the alternative is a file that claims to hold a row
 * that no longer exists.
 *
 * ## The file is a read surface
 *
 * A finished export is a complete copy of the cash book sitting on disk, so it
 * goes to the private `local` disk and is reached only through a signed,
 * expiring URL — the same protection receipts get, with the same limit: within
 * its window the link works for whoever holds it. RETENTION bounds that window
 * and the file's life together, and App\Console\Commands\PruneExports is what
 * actually removes it.
 *
 * ## One render per request, not one per click
 *
 * The button is a button, so it gets clicked twice. Without a guard each click
 * is its own job: two full copies of the book on disk, two transactions_exported
 * entries for one act, and two notifications offering the same thing.
 *
 * uniqueId() keys on the *request* — who asked, which format, and which rows —
 * rather than on the user or the format alone. That is the narrow reading on
 * purpose. A key of userId.format would also swallow the legitimate case: filter
 * the screen differently, click again, and the second export is silently
 * discarded while the screen says it is being processed. Including the row set
 * means only a genuine repeat of the same request is refused.
 *
 * Because the key *is* the request, the "sedang diproses" message the action
 * flashes stays true for the discarded click as well — an export of exactly
 * those rows in exactly that format really is on its way. That is what makes it
 * safe to drop the duplicate without telling anyone.
 */
class ExportCashBook implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const DISK = 'local';

    public const DIRECTORY = 'exports';

    /**
     * How long a finished file lives, and therefore how long its link works.
     *
     * One constant for both on purpose: a link that outlives its file 404s, and
     * a file that outlives its link is a copy of the book nobody can reach and
     * nothing will clean up.
     */
    public const RETENTION_HOURS = 24;

    /**
     * A retry would write a second file and a second audit entry for one act.
     * Rendering is deterministic — a failure here is a bad query, a missing
     * font or an exhausted memory limit, and none of those get better on a
     * second attempt.
     */
    public int $tries = 1;

    /** A long book through phpspreadsheet is slower than the default 60s. */
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
     *                                particular order — CashBook imposes its own
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
     * query happened to return them — the action calls reorder(), so there is
     * no ORDER BY at all and the database is free to hand the same set back in
     * a different order on a second run. Hashing them unsorted would produce a
     * different key for the same request, and the guard would do nothing
     * whatsoever without failing.
     *
     * md5 rather than a longer digest because this is a cache key, not a
     * signature: nothing here depends on an attacker being unable to construct
     * a collision, and the worst one could do is drop a duplicate export. The
     * digest is 32 characters whatever the book's size — what grows with the
     * book is the ids payload, which the job carries either way.
     */
    public function uniqueId(): string
    {
        $ids = $this->ids;

        sort($ids);

        return $this->userId.':'.$this->format.':'.md5(implode(',', $ids));
    }

    public function handle(): void
    {
        $user = User::find($this->userId);

        $book = new CashBook(Transaction::query()->whereKey($this->ids));

        $path = self::DIRECTORY.'/'.$this->fileName;

        match ($this->format) {
            'xlsx' => Excel::store(new TransactionsExport($book), $path, self::DISK),
            'pdf' => Storage::disk(self::DISK)->put($path, $this->renderPdf($book, $user?->name)),
            default => throw new \InvalidArgumentException("Format ekspor tidak dikenal: {$this->format}"),
        };

        // After the render, not before: rowCount() is accumulated by the fold
        // and is only final once every line has been written.
        $this->audit($user, $book->rowCount());

        if ($user !== null) {
            $this->announce($user, $path, $book->rowCount());
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
        Log::error('Ekspor buku kas gagal', [
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
            ->title('Ekspor buku kas gagal')
            ->body('Berkas '.$this->fileName.' tidak dapat dibuat. Coba lagi, dan hubungi admin bila berulang.')
            ->danger()
            ->sendToDatabase($user);
    }

    /**
     * The link is minted here rather than at dispatch, because it has to expire
     * with the file and the file does not exist until now.
     */
    private function announce(User $user, string $path, int $rows): void
    {
        $url = Storage::disk(self::DISK)->temporaryUrl($path, self::expiresAt());

        Notification::make()
            ->title('Buku kas siap diunduh')
            ->body($rows.' transaksi · '.strtoupper($this->format).' · tautan berlaku '.self::RETENTION_HOURS.' jam')
            ->success()
            ->actions([
                Action::make('download')
                    ->label('Unduh')
                    ->url($url, shouldOpenInNewTab: true)
                    ->markAsRead(),
            ])
            ->sendToDatabase($user);
    }

    public static function expiresAt(): Carbon
    {
        return now()->addHours(self::RETENTION_HOURS);
    }

    private function renderPdf(CashBook $book, ?string $printedBy): string
    {
        // Eager, unlike the spreadsheet: dompdf renders one HTML string, so the
        // whole book is in memory either way.
        $lines = $book->lines();

        $pdf = Pdf::loadView('pdf.buku-kas', [
            'lines' => $lines,
            'totals' => $book->totals(),
            'period' => $book->period(),
        ])->setPaper('a4', 'landscape');

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
            // Helvetica — see the note on fonts in the Blade template.
            $dompdf->getFontMetrics()->getFont('sans-serif'),
            8,
            [0.42, 0.45, 0.50],
        );
    }

    /**
     * One event for both formats, distinguished by a property.
     *
     * Downloading the book is a single act; the file extension is a detail of
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
            ->event('transactions_exported')
            ->withProperties([
                'file_name' => $this->fileName,
                'format' => $this->format,
                'rows' => $rows,
                // Which subset left the panel. Without this the entry says a
                // book was taken but not which pages.
                'filters' => $this->filters,
            ])
            ->log('Buku kas diunduh sebagai '.strtoupper($this->format));
    }
}
