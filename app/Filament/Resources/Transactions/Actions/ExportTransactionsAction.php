<?php

namespace App\Filament\Resources\Transactions\Actions;

use App\Exports\TransactionsExport;
use App\Filament\Resources\Transactions\TransactionResource;
use App\Reports\CashBook;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfWrapper;
use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads the cash book, as filtered on screen, as a spreadsheet or a PDF.
 *
 * Both formats are here rather than in a class each because everything that
 * matters about them is shared: who may do it, which rows they get, and the
 * entry it leaves in the audit log. Only the renderer differs. Two copies of an
 * authorization rule is one copy too many, and an audit trail that two classes
 * write in two shapes is worse than none.
 *
 * **It exports the filtered set, not the page.** getFilteredTableQuery() gives
 * the query with the caller's filters applied and no pagination, so a filtered
 * screen produces a filtered file. Its sibling getFilteredSortedTableQuery()
 * would also carry the table's sort, which is exactly what must not happen —
 * see App\Reports\CashBook for why the ledger imposes chronological order.
 *
 * **It is audited.** A copy of the cash book is a bulk read of data that every
 * screen in this panel otherwise gates by policy, and unlike a screen it leaves
 * the building. Nothing else records that it happened: the rows are only read,
 * so no model event fires and LogsActivity sees nothing.
 */
class ExportTransactionsAction
{
    public static function excel(): Action
    {
        return static::base('exportExcel')
            ->label('Unduh Excel')
            ->icon(Heroicon::OutlinedTableCells)
            ->action(function (Component&HasTable $livewire): BinaryFileResponse {
                $book = new CashBook($livewire->getFilteredTableQuery());

                $fileName = static::fileName('xlsx');

                // The file is written before this returns, so the row count the
                // ledger accumulated is final by the time it is logged.
                $response = Excel::download(new TransactionsExport($book), $fileName);

                static::audit($livewire, $fileName, 'xlsx', $book->rowCount());

                return $response;
            });
    }

    public static function pdf(): Action
    {
        return static::base('exportPdf')
            ->label('Unduh PDF')
            ->icon(Heroicon::OutlinedDocumentText)
            ->action(function (Component&HasTable $livewire): StreamedResponse {
                $book = new CashBook($livewire->getFilteredTableQuery());

                // Eager, unlike the spreadsheet: dompdf renders one HTML string,
                // so the whole book is in memory either way.
                $lines = $book->lines();

                $fileName = static::fileName('pdf');

                $pdf = Pdf::loadView('pdf.buku-kas', [
                    'lines' => $lines,
                    'totals' => $book->totals(),
                    'period' => $book->period(),
                ])->setPaper('a4', 'landscape');

                static::stampFooter($pdf, Auth::user()?->name);

                static::audit($livewire, $fileName, 'pdf', $book->rowCount());

                // Not $pdf->download(): that returns a plain
                // Illuminate\Http\Response, and Livewire's SupportFileDownloads
                // only recognises a BinaryFileResponse or a StreamedResponse.
                // Anything else falls through to the ordinary return path and
                // Livewire tries to JSON-encode the response object, which
                // fails with "Type is not supported" — a message that says
                // nothing about the actual cause. streamDownload() gives back a
                // StreamedResponse, which it does recognise.
                $bytes = $pdf->output();

                return response()->streamDownload(
                    function () use ($bytes): void {
                        echo $bytes;
                    },
                    $fileName,
                    ['Content-Type' => 'application/pdf'],
                );
            });
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
    private static function stampFooter(PdfWrapper $pdf, ?string $printedBy): void
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
     * Everything the two formats agree on.
     */
    private static function base(string $name): Action
    {
        return Action::make($name)
            ->color('gray')
            // Checked server-side before the action mounts. The rule lives on
            // the resource next to the other authorization checks, so a future
            // second mounting point cannot get a weaker one.
            ->visible(fn (): bool => TransactionResource::canExport());
    }

    private static function fileName(string $extension): string
    {
        return 'buku-kas-'.now()->format('Y-m-d-His').'.'.$extension;
    }

    /**
     * One event for both formats, distinguished by a property.
     *
     * Downloading the book is a single act; the file extension is a detail of
     * it, not a different thing that happened. Filtering the log for "who took
     * a copy of the book" should not mean remembering to check two event keys.
     */
    private static function audit(Component&HasTable $livewire, string $fileName, string $format, int $rows): void
    {
        activity('monitoring')
            ->event('transactions_exported')
            ->withProperties([
                'file_name' => $fileName,
                'format' => $format,
                'rows' => $rows,
                // Which subset left the panel. Without this the entry says a
                // book was taken but not which pages.
                'filters' => array_filter($livewire->tableFilters ?? []),
            ])
            ->log('Buku kas diunduh sebagai '.strtoupper($format));
    }
}
